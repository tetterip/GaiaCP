<?php

namespace Plugins\Hestia;

use App\Models\Server;
use App\Models\Service;
use App\Provisioning\AccountState;
use App\Provisioning\AccountUsage;
use App\Provisioning\HostingProvisioner;
use App\Provisioning\ListsAccounts;
use App\Provisioning\PanelAccount;
use App\Provisioning\ProvisioningException;
use App\Support\DomainName;

/**
 * HestiaCP: one HestiaCP user per hosting service (the service's username) on a package, with the service's domain
 * added as its web, DNS and mail domain. The user's password is both the HestiaCP login and the SFTP / SSH password.
 */
class HestiaProvisioner implements HostingProvisioner, ListsAccounts
{
    /** HestiaCP's own package for the admin user, never one to sell. */
    private const SYSTEM_PACKAGE = 'system';

    public function __construct(
        private HestiaClient $hestia,
        private Server $server,
    ) {}

    public function testConnection(): string
    {
        $info = $this->hestia->list('v-list-sys-info')['sysinfo'] ?? [];

        return 'HestiaCP '.(($info['HESTIA'] ?? '') ?: 'unknown version');
    }

    public function packages(): array
    {
        $packages = array_keys($this->hestia->list('v-list-user-packages', timeout: 8));

        return array_values(array_filter(array_map('strval', $packages), fn (string $name) => $name !== self::SYSTEM_PACKAGE));
    }

    public function accountState(Service $service): AccountState
    {
        $user = $this->findUser($service);

        return match (true) {
            $user === null => AccountState::Missing,
            ($user['SUSPENDED'] ?? 'no') === 'yes' => AccountState::Suspended,
            default => AccountState::Active,
        };
    }

    /** Every user but the panel's own (the system package): v-list-users, then each one's first web domain. */
    public function accounts(): array
    {
        $accounts = [];

        foreach ($this->hestia->list('v-list-users', timeout: 60) as $username => $user) {
            if (! is_array($user) || ($user['PACKAGE'] ?? null) === self::SYSTEM_PACKAGE) {
                continue;
            }

            $domains = array_map('strval', array_keys($this->hestia->list('v-list-web-domains', [(string) $username])));
            $accounts[] = new PanelAccount(
                username: (string) $username,
                domain: $domains[0] ?? null,
                package: filled($user['PACKAGE'] ?? null) ? (string) $user['PACKAGE'] : null,
                suspended: ($user['SUSPENDED'] ?? 'no') === 'yes',
            );
        }

        return $accounts;
    }

    /** The service's domain when the user has it as a web domain, else the user's first one. */
    public function accountDomain(Service $service): ?string
    {
        if ($this->findUser($service) === null) {
            return null;
        }

        $domains = array_map('strval', array_keys($this->hestia->list('v-list-web-domains', [$this->username($service)])));

        if ($service->domain && in_array(DomainName::toAscii($service->domain), $domains, true)) {
            return $service->domain;
        }

        return $domains[0] ?? null;
    }

    public function createAccount(Service $service, string $password): void
    {
        if (! $service->domain || ! $service->package || ! $service->username) {
            throw new ProvisioningException('A HestiaCP account needs a domain, a package and a username.');
        }

        $this->checkPassword($password);
        $username = $this->username($service);

        // v-add-user USER PASSWORD EMAIL [PACKAGE] [NAME]; HestiaCP refuses quotes and < > in the name.
        $this->hestia->run('v-add-user', [
            $username,
            $password,
            $service->client->email,
            $service->package,
            trim(preg_replace('/["\'<>]/', '', $service->client->name)),
        ], secrets: [$password], timeout: 180);

        try {
            $this->hestia->run('v-add-domain', [$username, DomainName::toAscii($service->domain)], timeout: 180);
        } catch (ProvisioningException $e) {
            // Leave nothing half made, so a retry starts clean.
            rescue(fn () => $this->hestia->run('v-delete-user', [$username], timeout: 180), report: false);

            throw $e;
        }
    }

    public function suspendAccount(Service $service, string $reason): void
    {
        // HestiaCP has no reason on a suspension; it stays in GaiaCP.
        $this->hestia->run('v-suspend-user', [$this->username($service)], timeout: 120);
    }

    public function unsuspendAccount(Service $service): void
    {
        $this->hestia->run('v-unsuspend-user', [$this->username($service)], timeout: 120);
    }

    public function terminateAccount(Service $service): void
    {
        $this->hestia->run('v-delete-user', [$this->username($service)], timeout: 180);
    }

    public function changePackage(Service $service, string $package): void
    {
        $this->hestia->run('v-change-user-package', [$this->username($service), $package], timeout: 120);
    }

    /** The HestiaCP login, which is also the SFTP / SSH password of the user. */
    public function changePassword(Service $service, string $password): void
    {
        $this->checkPassword($password);

        $this->hestia->run('v-change-user-password', [$this->username($service), $password], secrets: [$password]);
    }

    /**
     * HestiaCP has no one-time login link (no API command signs a user in), so this is the login page: the client
     * signs in with their username and password.
     */
    public function loginUrl(Service $service, string $clientIp): string
    {
        $this->username($service);

        return sprintf('https://%s:%d/login/', $this->server->hostname, $this->server->port);
    }

    /** HestiaCP has no one-time login either: its login page. */
    public function adminLoginUrl(string $clientIp): string
    {
        return sprintf('https://%s:%d/login/', $this->server->hostname, $this->server->port);
    }

    /** v-list-user used => limit fields, by AccountUsage::COUNTS key. */
    private const COUNTS = [
        'email_accounts' => ['U_MAIL_ACCOUNTS', 'MAIL_ACCOUNTS'],
        'databases' => ['U_DATABASES', 'DATABASES'],
        'sites' => ['U_WEB_DOMAINS', 'WEB_DOMAINS'],
    ];

    /**
     * From v-list-user: disk (U_DISK of DISK_QUOTA) and bandwidth (U_BANDWIDTH of BANDWIDTH) in MB, limits
     * "unlimited" or a number; mail accounts, databases and web domains against the package's limits; the databases'
     * space (U_DISK_DB, MB).
     */
    public function usage(Service $service): AccountUsage
    {
        $user = $this->findUser($service)
            ?? throw new ProvisioningException("There is no HestiaCP user {$service->username} on {$this->server->name}.");

        $number = fn (mixed $value) => is_numeric($value) ? (int) $value : null;
        $megabytes = fn (mixed $value) => is_numeric($value) ? (int) round((float) $value * 1024 ** 2) : null;

        $counts = [];
        foreach (self::COUNTS as $key => [$used, $limit]) {
            if (array_key_exists($used, $user)) {
                $counts[$key] = [$number($user[$used]), $number($user[$limit] ?? null)];
            }
        }
        if (array_key_exists('U_DISK_DB', $user)) {
            $counts['database_disk'] = [$megabytes($user['U_DISK_DB']), null];
        }

        return new AccountUsage(
            diskUsed: $megabytes($user['U_DISK'] ?? null),
            diskLimit: $megabytes($user['DISK_QUOTA'] ?? null) ?: null,
            bandwidthUsed: $megabytes($user['U_BANDWIDTH'] ?? null),
            bandwidthLimit: $megabytes($user['BANDWIDTH'] ?? null) ?: null,
            counts: $counts,
        );
    }

    /** @return array<string, mixed>|null the user's v-list-user fields, or null if there is no such user */
    private function findUser(Service $service): ?array
    {
        $username = $this->username($service);

        try {
            $data = $this->hestia->list('v-list-user', [$username]);
        } catch (ProvisioningException $e) {
            if ($e->getCode() === HestiaClient::NOT_FOUND) {
                return null;
            }

            throw $e;
        }

        return is_array($data[$username] ?? null) ? $data[$username] : null;
    }

    /** HestiaCP reads a password starting with /tmp/ as a file on the server; never let one through. */
    private function checkPassword(string $password): void
    {
        if (str_starts_with($password, '/')) {
            throw new ProvisioningException('A HestiaCP password cannot start with "/".');
        }
    }

    private function username(Service $service): string
    {
        return $service->username
            ?: throw new ProvisioningException('This service has no HestiaCP username.');
    }
}
