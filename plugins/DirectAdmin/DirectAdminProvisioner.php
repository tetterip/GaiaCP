<?php

namespace Plugins\DirectAdmin;

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
 * DirectAdmin: one DirectAdmin user per hosting service (the service's username), created by the server's login (the
 * admin or a reseller) on one of its user packages, with the service's domain as the user's domain, on the server's
 * "IP for new accounts" or else the login's first IP. The user's password is its DirectAdmin login.
 */
class DirectAdminProvisioner implements HostingProvisioner, ListsAccounts
{
    public function __construct(
        private DirectAdminClient $da,
        private Server $server,
    ) {}

    public function testConnection(): string
    {
        // The users the login owns: proves the login and its API access without needing admin rights.
        $users = $this->list($this->da->get('SHOW_USERS'));

        return sprintf('DirectAdmin, %d account(s) owned by %s', count($users), $this->server->username);
    }

    public function packages(): array
    {
        return $this->list($this->da->get('PACKAGES_USER', timeout: 8));
    }

    public function accountState(Service $service): AccountState
    {
        $config = $this->findUser($service);

        return match (true) {
            $config === null => AccountState::Missing,
            strtolower((string) ($config['suspended'] ?? 'no')) === 'yes' => AccountState::Suspended,
            default => AccountState::Active,
        };
    }

    /** The login's users (SHOW_USERS), each with its SHOW_USER_CONFIG. */
    public function accounts(): array
    {
        return array_map(function (string $username) {
            $config = $this->da->get('SHOW_USER_CONFIG', ['user' => $username]);

            return new PanelAccount(
                username: $username,
                domain: filled($config['domain'] ?? null) ? (string) $config['domain'] : null,
                package: filled($config['package'] ?? null) ? (string) $config['package'] : null,
                suspended: strtolower((string) ($config['suspended'] ?? 'no')) === 'yes',
            );
        }, $this->list($this->da->get('SHOW_USERS', timeout: 60)));
    }

    public function accountDomain(Service $service): ?string
    {
        $config = $this->findUser($service);

        if ($config === null) {
            return null;
        }

        $domain = (string) ($config['domain'] ?? '');

        return $service->domain && $domain === DomainName::toAscii($service->domain) ? $service->domain : ($domain ?: null);
    }

    public function createAccount(Service $service, string $password): void
    {
        if (! $service->domain || ! $service->package || ! $service->username) {
            throw new ProvisioningException('A DirectAdmin account needs a domain, a package and a username.');
        }

        $this->da->post('ACCOUNT_USER', [
            'action' => 'create',
            'add' => 'Submit',
            'username' => $this->username($service),
            'email' => $service->client->email,
            'passwd' => $password,
            'passwd2' => $password,
            'domain' => DomainName::toAscii($service->domain),
            'package' => $service->package,
            'ip' => $this->ip(),
            'notify' => 'no', // GaiaCP sends its own welcome email
        ], secrets: [$password], timeout: 180);
    }

    public function suspendAccount(Service $service, string $reason): void
    {
        // DirectAdmin's legacy API takes no reason; it stays in GaiaCP.
        $this->da->post('SELECT_USERS', ['suspend' => 'Suspend', 'select0' => $this->username($service)], timeout: 120);
    }

    public function unsuspendAccount(Service $service): void
    {
        $this->da->post('SELECT_USERS', ['suspend' => 'Unsuspend', 'select0' => $this->username($service)], timeout: 120);
    }

    public function terminateAccount(Service $service): void
    {
        $this->da->post('SELECT_USERS', ['confirmed' => 'Confirm', 'delete' => 'yes', 'select0' => $this->username($service)], timeout: 180);
    }

    public function changePackage(Service $service, string $package): void
    {
        $this->da->post('MODIFY_USER', ['action' => 'package', 'user' => $this->username($service), 'package' => $package], timeout: 120);
    }

    /** The DirectAdmin login, also used for FTP and the databases. */
    public function changePassword(Service $service, string $password): void
    {
        $this->da->post('USER_PASSWD', [
            'username' => $this->username($service),
            'passwd' => $password,
            'passwd2' => $password,
            'options' => 'yes',
            'system' => 'yes',
            'ftp' => 'yes',
            'database' => 'yes',
        ], secrets: [$password]);
    }

    /** DirectAdmin's login page: the client signs in with their username and password. */
    public function loginUrl(Service $service, string $clientIp): string
    {
        $this->username($service);

        return $this->panelUrl();
    }

    public function adminLoginUrl(string $clientIp): string
    {
        return $this->panelUrl();
    }

    /**
     * SHOW_USER_USAGE (used) against SHOW_USER_CONFIG (limits): disk `quota` and `bandwidth` in MB, limits a number
     * or "unlimited"; mail accounts (`nemails`), databases (`mysql`), domains (`vdomains`), parked domains
     * (`domainptr`) and subdomains (`nsubdomains`).
     */
    public function usage(Service $service): AccountUsage
    {
        $config = $this->findUser($service)
            ?? throw new ProvisioningException("There is no DirectAdmin user {$service->username} on {$this->server->name}.");
        $used = $this->da->get('SHOW_USER_USAGE', ['user' => $this->username($service)]);

        $number = fn (mixed $value) => is_numeric($value) ? (int) $value : null;
        $megabytes = fn (mixed $value) => is_numeric($value) ? (int) round((float) $value * 1024 ** 2) : null;

        $counts = [];
        foreach (['email_accounts' => 'nemails', 'databases' => 'mysql', 'sites' => 'vdomains', 'parked_domains' => 'domainptr', 'subdomains' => 'nsubdomains'] as $key => $field) {
            if (array_key_exists($field, $used)) {
                $counts[$key] = [$number($used[$field]), $number($config[$field] ?? null)];
            }
        }

        return new AccountUsage(
            diskUsed: $megabytes($used['quota'] ?? null),
            diskLimit: $megabytes($config['quota'] ?? null) ?: null,
            bandwidthUsed: $megabytes($used['bandwidth'] ?? null),
            bandwidthLimit: $megabytes($config['bandwidth'] ?? null) ?: null,
            counts: $counts,
        );
    }

    /** @return array<string, mixed>|null the user's SHOW_USER_CONFIG, or null when the login owns no such user */
    private function findUser(Service $service): ?array
    {
        $username = $this->username($service);

        if (! in_array($username, $this->list($this->da->get('SHOW_USERS')), true)) {
            return null;
        }

        return $this->da->get('SHOW_USER_CONFIG', ['user' => $username]);
    }

    /** The IP new accounts go on: the server's "IP for new accounts", else the login's first IP. */
    private function ip(): string
    {
        if (filled($this->server->shared_ip)) {
            return $this->server->shared_ip;
        }

        return $this->list($this->da->get('SHOW_RESELLER_IPS'))[0]
            ?? throw new ProvisioningException("No IP address found for {$this->server->username} on {$this->server->name}; set one on the server in GaiaCP.");
    }

    /**
     * A list reply ("list[]=a&list[]=b"), or [] for an empty one.
     *
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private function list(array $data): array
    {
        return array_values(array_filter(array_map('strval', (array) ($data['list'] ?? [])), fn (string $value) => $value !== ''));
    }

    private function panelUrl(): string
    {
        return sprintf('https://%s:%d/', $this->server->hostname, $this->server->port);
    }

    private function username(Service $service): string
    {
        return $service->username
            ?: throw new ProvisioningException('This service has no DirectAdmin username.');
    }
}
