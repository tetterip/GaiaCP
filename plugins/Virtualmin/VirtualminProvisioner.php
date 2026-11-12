<?php

namespace Plugins\Virtualmin;

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
 * Virtualmin: one top-level virtual server per hosting service, for the service's domain, owned by a Unix user of the
 * service's username, on a Virtualmin plan (the service's package) with the plan's features. The password is the
 * Virtualmin / Usermin login and the user's FTP and SSH password. The calls are those of Blesta's Virtualmin module.
 */
class VirtualminProvisioner implements HostingProvisioner, ListsAccounts
{
    public function __construct(
        private VirtualminClient $virtualmin,
        private Server $server,
    ) {}

    public function testConnection(): string
    {
        $plans = $this->virtualmin->list('list-plans', timeout: 15);

        return sprintf('Virtualmin, %d plan%s', count($plans), count($plans) === 1 ? '' : 's');
    }

    public function packages(): array
    {
        return array_values(array_filter(array_map(
            fn (array $plan) => $plan['values']['name'][0] ?? $plan['name'],
            $this->virtualmin->list('list-plans', timeout: 8),
        ), 'filled'));
    }

    public function accountState(Service $service): AccountState
    {
        $domain = $this->findDomain($service);

        return match (true) {
            $domain === null => AccountState::Missing,
            self::disabled($domain) => AccountState::Suspended,
            default => AccountState::Active,
        };
    }

    /** Every top-level virtual server (sub-servers and aliases belong to one of them). */
    public function accounts(): array
    {
        return array_map(fn (array $domain) => new PanelAccount(
            username: self::value($domain, 'username') ?? $domain['name'],
            domain: DomainName::normalize($domain['name']) ?: null,
            package: self::value($domain, 'plan'),
            suspended: self::disabled($domain),
        ), $this->virtualmin->list('list-domains', ['toplevel' => ''], timeout: 120));
    }

    public function accountDomain(Service $service): ?string
    {
        $domain = $this->findDomain($service);

        return $domain === null ? null : DomainName::normalize($domain['name']);
    }

    public function createAccount(Service $service, string $password): void
    {
        if (! $service->domain || ! $service->package || ! $service->username) {
            throw new ProvisioningException('A Virtualmin account needs a domain, a package and a username.');
        }

        $this->virtualmin->run('create-domain', [
            'domain' => DomainName::toAscii($service->domain),
            'user' => $service->username,
            'pass' => $password,
            'plan' => $service->package,
            'features-from-plan' => '',
            'email' => (string) $service->client->email,
            'desc' => trim(preg_replace('/[\r\n]+/', ' ', $service->client->name)),
        ], secrets: [$password], timeout: 300);
    }

    public function suspendAccount(Service $service, string $reason): void
    {
        $this->virtualmin->run('disable-domain', ['domain' => $this->domainName($service), 'why' => $reason], timeout: 180);
    }

    public function unsuspendAccount(Service $service): void
    {
        $this->virtualmin->run('enable-domain', ['domain' => $this->domainName($service)], timeout: 180);
    }

    /** Deletes the virtual server with its sub-servers, files, mail and databases, and its Unix user. */
    public function terminateAccount(Service $service): void
    {
        $this->virtualmin->run('delete-domain', ['domain' => $this->domainName($service)], timeout: 300);
    }

    public function changePackage(Service $service, string $package): void
    {
        $this->virtualmin->run('modify-domain', ['domain' => $this->domainName($service), 'apply-plan' => $package], timeout: 180);
    }

    public function changePassword(Service $service, string $password): void
    {
        $this->virtualmin->run('modify-domain', ['domain' => $this->domainName($service), 'pass' => $password], secrets: [$password]);
    }

    /** Virtualmin has no one-time login link over its API: its login page, where the client signs in to Usermin / Virtualmin. */
    public function loginUrl(Service $service, string $clientIp): string
    {
        $this->username($service);

        return sprintf('https://%s:%d/', $this->server->hostname, $this->server->port);
    }

    public function adminLoginUrl(string $clientIp): string
    {
        return sprintf('https://%s:%d/', $this->server->hostname, $this->server->port);
    }

    /**
     * From list-domains --multiline: disk ("Server byte quota used", limit "Server quota", e.g. "1 GB" or
     * "Unlimited"), bandwidth ("Bandwidth byte usage" of "Bandwidth byte limit"), databases ("Databases count" of
     * "Maximum databases") and their space ("Databases byte size").
     */
    public function usage(Service $service): AccountUsage
    {
        $domain = $this->findDomain($service)
            ?? throw new ProvisioningException("There is no Virtualmin virtual server for {$service->username} on {$this->server->name}.");

        $bytes = fn (string ...$fields) => collect($fields)->map(fn (string $field) => self::bytes(self::value($domain, $field)))->first(fn (?int $v) => $v !== null);
        $number = fn (string $field) => is_numeric($v = self::value($domain, $field)) ? (int) $v : null;

        $counts = [];
        if (($databases = $number('databases_count')) !== null) {
            $counts['databases'] = [$databases, $number('maximum_databases')];
        }
        if (($databaseDisk = $bytes('databases_byte_size', 'databases_size')) !== null) {
            $counts['database_disk'] = [$databaseDisk, null];
        }

        return new AccountUsage(
            diskUsed: $bytes('server_byte_quota_used', 'server_quota_used'),
            diskLimit: $bytes('server_byte_quota', 'server_quota') ?: null,
            bandwidthUsed: $bytes('bandwidth_byte_usage', 'bandwidth_usage'),
            bandwidthLimit: $bytes('bandwidth_byte_limit', 'bandwidth_limit') ?: null,
            counts: $counts,
        );
    }

    /**
     * The user's top-level virtual server: list-domains --user USER --toplevel; null when Virtualmin has no such user.
     *
     * @return array{name: string, values: array<string, list<string>>}|null
     */
    private function findDomain(Service $service): ?array
    {
        $username = $this->username($service);

        try {
            $domains = $this->virtualmin->list('list-domains', ['user' => $username, 'toplevel' => '']);
        } catch (ProvisioningException $e) {
            if (preg_match('/does not exist|not found|no virtual server/i', $e->getMessage())) {
                return null;
            }

            throw $e;
        }

        // Only one of this user's: never act on a server owned by someone else.
        foreach ($domains as $domain) {
            if ((self::value($domain, 'username') ?? $username) === $username) {
                return $domain;
            }
        }

        return null;
    }

    private function domainName(Service $service): string
    {
        return ($this->findDomain($service) ?? throw new ProvisioningException("There is no Virtualmin virtual server for {$service->username} on {$this->server->name}."))['name'];
    }

    /** @param  array{name: string, values: array<string, list<string>>}  $domain */
    private static function value(array $domain, string $field): ?string
    {
        $value = trim($domain['values'][$field][0] ?? '');

        return $value === '' ? null : $value;
    }

    /** list-domains shows "Disabled: <why>" only for a disabled server. */
    private static function disabled(array $domain): bool
    {
        return array_key_exists('disabled', $domain['values']) && ! in_array(strtolower((string) self::value($domain, 'disabled')), ['no', '0'], true);
    }

    /** "1234567", "1.5 GB", "512 kB" or "Unlimited" as bytes; null when it is none of these (unlimited is null). */
    public static function bytes(?string $value): ?int
    {
        if ($value === null || ! preg_match('/^(\d+(?:\.\d+)?)\s*([kmgtp]?i?b|bytes)?$/i', trim($value), $m)) {
            return null;
        }

        $unit = strtolower($m[2] ?? '');
        $power = ['' => 0, 'b' => 0, 'bytes' => 0, 'kb' => 1, 'kib' => 1, 'mb' => 2, 'mib' => 2, 'gb' => 3, 'gib' => 3, 'tb' => 4, 'tib' => 4, 'pb' => 5, 'pib' => 5][$unit] ?? 0;

        return (int) round((float) $m[1] * 1024 ** $power);
    }

    private function username(Service $service): string
    {
        return $service->username
            ?: throw new ProvisioningException('This service has no Virtualmin username.');
    }
}
