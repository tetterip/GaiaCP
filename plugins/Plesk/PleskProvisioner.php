<?php

namespace Plugins\Plesk;

use App\Models\Client;
use App\Models\Server;
use App\Models\Service;
use App\Provisioning\AccountState;
use App\Provisioning\AccountUsage;
use App\Provisioning\HostingProvisioner;
use App\Provisioning\InstallsCertificates;
use App\Provisioning\ListsAccounts;
use App\Provisioning\OffersShortcuts;
use App\Provisioning\PanelAccount;
use App\Provisioning\ProvisioningException;
use SimpleXMLElement;

/**
 * Plesk with service plans: one Plesk customer per GaiaCP client (found again
 * through its external-id "gaiacp-client-<id>"), one subscription (webspace)
 * per hosting service, identified by the service's domain. The customer's
 * login and password come from the client's first hosting service on that
 * server; each subscription's system (FTP) user is the service's username.
 */
class PleskProvisioner implements HostingProvisioner, InstallsCertificates, ListsAccounts, OffersShortcuts
{
    /** webspace gen_setup status values */
    private const ACTIVE = 0;

    private const SUSPENDED_BY_ADMIN = 16;

    public function __construct(
        private PleskClient $plesk,
        private Server $server,
    ) {}

    public function testConnection(): string
    {
        $result = $this->plesk->call('server', 'get', ['stat' => []]);

        return 'Plesk '.((string) ($result[0]->stat->version->plesk_version ?? '') ?: 'unknown version');
    }

    public function packages(): array
    {
        $results = $this->plesk->call('service-plan', 'get', ['filter' => []], allowNotFound: true, timeout: 8);

        return array_values(array_filter(array_map(fn (SimpleXMLElement $r) => (string) $r->name, $results)));
    }

    public function accountState(Service $service): AccountState
    {
        $webspace = $this->findWebspace($service);

        return match (true) {
            $webspace === null => AccountState::Missing,
            (int) $webspace->data->gen_info->status === self::ACTIVE => AccountState::Active,
            default => AccountState::Suspended,
        };
    }

    /**
     * Every subscription: its domain, system (FTP) user, status and service plan (the subscription's plan guid, named
     * from service-plan get). Datasets in the schema's order.
     */
    public function accounts(): array
    {
        $plans = [];
        foreach ($this->plesk->call('service-plan', 'get', ['filter' => []], allowNotFound: true) as $plan) {
            $plans[(string) $plan->guid] = (string) $plan->name;
        }

        $webspaces = $this->plesk->call('webspace', 'get', [
            'filter' => [],
            'dataset' => ['gen_info' => [], 'hosting' => [], 'subscriptions' => []],
        ], allowNotFound: true, timeout: 60);

        return array_values(array_map(function (SimpleXMLElement $webspace) use ($plans) {
            $ftpLogin = null;
            foreach ($webspace->data->hosting->vrt_hst->property ?? [] as $property) {
                if ((string) $property->name === 'ftp_login') {
                    $ftpLogin = (string) $property->value;
                }
            }

            $guid = (string) ($webspace->data->subscriptions->subscription->plan->{'plan-guid'} ?? '');
            $domain = (string) $webspace->data->gen_info->name;

            return new PanelAccount(
                username: $ftpLogin ?: $domain,
                domain: $domain ?: null,
                package: $plans[$guid] ?? null,
                suspended: (int) $webspace->data->gen_info->status !== self::ACTIVE,
            );
        }, $webspaces));
    }

    public function accountDomain(Service $service): ?string
    {
        $webspace = $this->findWebspace($service);

        if ($webspace === null) {
            return null;
        }

        // Same domain by definition; what matters is whether it belongs to this client.
        $ours = (int) $webspace->data->gen_info->{'owner-id'} === $this->findCustomerId($service->client);

        return $ours ? $service->domain : "{$service->domain} (another Plesk customer)";
    }

    public function createAccount(Service $service, string $password): void
    {
        if (! $service->domain || ! $service->package || ! $service->username) {
            throw new ProvisioningException('A Plesk subscription needs a domain, a service plan and a username.');
        }

        $customerId = $this->findCustomerId($service->client) ?? $this->createCustomer($service, $password);
        $ip = $this->server->shared_ip ?: $this->firstSharedIp();

        // Element order matters to Plesk's schema; status is left out (new subscriptions are active).
        $this->plesk->call('webspace', 'add', [
            'gen_setup' => [
                'name' => $service->domain,
                'owner-id' => $customerId,
                'htype' => 'vrt_hst',
                'ip_address' => $ip,
            ],
            'hosting' => [
                'vrt_hst' => [
                    'property' => [
                        ['name' => 'ftp_login', 'value' => $service->username],
                        ['name' => 'ftp_password', 'value' => $password],
                    ],
                    'ip_address' => $ip,
                ],
            ],
            'plan-name' => $service->package,
        ], timeout: 180);
    }

    public function suspendAccount(Service $service, string $reason): void
    {
        // Plesk has no free-text reason on a subscription; the reason stays in GaiaCP.
        $this->setStatus($service, self::SUSPENDED_BY_ADMIN);
    }

    public function unsuspendAccount(Service $service): void
    {
        $this->setStatus($service, self::ACTIVE);
    }

    public function terminateAccount(Service $service): void
    {
        // The customer account stays, so the client's other subscriptions and login are untouched.
        $this->plesk->call('webspace', 'del', ['filter' => ['name' => $this->domain($service)]], timeout: 180);
    }

    public function changePackage(Service $service, string $package): void
    {
        $plan = $this->plesk->call('service-plan', 'get', ['filter' => ['name' => $package]], allowNotFound: true)[0] ?? null;

        if ($plan === null) {
            throw new ProvisioningException("There is no service plan \"{$package}\" on {$this->server->name}.");
        }

        $this->plesk->call('webspace', 'switch-subscription', [
            'filter' => ['name' => $this->domain($service)],
            'plan-guid' => (string) $plan->guid,
        ]);
    }

    /** The subscription's system user (FTP / SSH). The client's Plesk login is the customer's and is left alone. */
    public function changePassword(Service $service, string $password): void
    {
        // As api-php-lib's Webspace::setProperties().
        $this->plesk->call('webspace', 'set', [
            'filter' => ['name' => $this->domain($service)],
            'values' => ['hosting' => ['vrt_hst' => ['property' => [['name' => 'ftp_password', 'value' => $password]]]]],
        ]);
    }

    /**
     * A Plesk session for the subscription's owner, as api-php-lib's Session::create(), opened through rsession_init.
     * Only a customer's login: a subscription the admin or a reseller owns gets no link.
     */
    public function loginUrl(Service $service, string $clientIp): string
    {
        return $this->customerSession($service, $clientIp);
    }

    /** Customer panel pages (rsession_init.php's success_redirect_url), as WHMCS's Plesk module opens them. */
    public const SHORTCUTS = [
        'mail' => ['Mail', 'heroicon-o-envelope', '/smb/email-address/list'],
        'applications' => ['Applications', 'heroicon-o-squares-2x2', '/smb/app/index'],
        'files' => ['File Manager', 'heroicon-o-folder-open', '/smb/file-manager/list'],
        'databases' => ['Databases', 'heroicon-o-circle-stack', '/smb/database/list'],
        'statistics' => ['Statistics', 'heroicon-o-chart-bar', '/smb/statistics/details'],
        'users' => ['Users', 'heroicon-o-users', '/smb/user/list'],
    ];

    public function shortcuts(): array
    {
        return array_map(fn (array $shortcut) => ['label' => __($shortcut[0]), 'icon' => $shortcut[1]], self::SHORTCUTS);
    }

    public function shortcutUrl(Service $service, string $key, string $clientIp): string
    {
        $path = self::SHORTCUTS[$key][2] ?? throw new ProvisioningException("Plesk has no shortcut {$key}.");

        return $this->customerSession($service, $clientIp).'&success_redirect_url='.rawurlencode($path);
    }

    /** create_session for the customer who owns the service's subscription. */
    private function customerSession(Service $service, string $clientIp): string
    {
        $webspace = $this->findWebspace($service)
            ?? throw new ProvisioningException("There is no subscription {$service->domain} on {$this->server->name}.");

        $customer = $this->plesk->call('customer', 'get', [
            'filter' => ['id' => (int) $webspace->data->gen_info->{'owner-id'}],
            'dataset' => ['gen_info' => []],
        ], allowNotFound: true)[0] ?? throw new ProvisioningException("The subscription {$service->domain} does not belong to a Plesk customer.");

        return $this->session((string) $customer->data->gen_info->login, $clientIp);
    }

    /** create_session for the server's own login ("admin" when it signs in with a secret key alone). */
    public function adminLoginUrl(string $clientIp): string
    {
        return $this->session($this->server->username ?: 'admin', $clientIp);
    }

    private function session(string $login, string $clientIp): string
    {
        $session = $this->plesk->call('server', 'create_session', [
            'login' => $login,
            'data' => ['user_ip' => base64_encode($clientIp), 'source_server' => ''],
        ])[0] ?? null;

        $id = (string) ($session->id ?? '') ?: throw new ProvisioningException('Plesk create_session returned no session.');

        return sprintf('https://%s:%d/enterprise/rsession_init.php?PLESKSESSID=%s', $this->server->hostname, $this->server->port, rawurlencode($id));
    }

    /** Plesk stat => limit names, by AccountUsage::COUNTS key. */
    private const STATS = [
        'email_accounts' => ['box', 'max_box'],
        'databases' => ['db', 'max_db'],
        'sites' => ['sites', 'max_site'],
        'subdomains' => ['subdom', 'max_subdom'],
    ];

    /**
     * The subscription's disk use (gen_info real_size), this month's traffic and counts (stat), its plan's limits
     * (-1 = unlimited) and its databases' size (disk_usage dbases), sizes in bytes. Datasets in the schema's order.
     */
    public function usage(Service $service): AccountUsage
    {
        $webspace = $this->plesk->call('webspace', 'get', [
            'filter' => ['name' => $this->domain($service)],
            'dataset' => ['gen_info' => [], 'limits' => [], 'stat' => [], 'disk_usage' => []],
        ], allowNotFound: true)[0] ?? throw new ProvisioningException("There is no subscription {$service->domain} on {$this->server->name}.");

        $limits = [];
        foreach ($webspace->data->limits->limit ?? [] as $limit) {
            $limits[(string) $limit->name] = (int) $limit->value;
        }

        $number = fn (?SimpleXMLElement $node) => $node !== null && is_numeric((string) $node) ? (int) (string) $node : null;
        $limit = fn (string $name, bool $bytes = false) => isset($limits[$name]) && $limits[$name] >= ($bytes ? 1 : 0) ? $limits[$name] : null;

        $counts = [];
        foreach (self::STATS as $key => [$stat, $max]) {
            if (isset($webspace->data->stat->{$stat})) {
                $counts[$key] = [$number($webspace->data->stat->{$stat}), $limit($max)];
            }
        }
        if (isset($webspace->data->disk_usage->dbases)) {
            $counts['database_disk'] = [$number($webspace->data->disk_usage->dbases), null];
        }

        return new AccountUsage(
            diskUsed: $number($webspace->data->gen_info->real_size ?? null),
            diskLimit: $limit('disk_space', bytes: true),
            bandwidthUsed: $number($webspace->data->stat->traffic ?? null),
            bandwidthLimit: $limit('max_traffic', bytes: true),
            counts: $counts,
        );
    }

    /**
     * A DNS record in the subscription's zone: Plesk's XML API can't write files. It only proves the domain when the
     * domain uses this server's nameservers.
     */
    public function certificateValidationMethods(): array
    {
        return ['dns'];
    }

    /** As api-php-lib's Dns::create(): the record's host is relative to the zone; one already there is left alone. */
    public function placeCertificateValidation(Service $hosting, string $method, array $details): void
    {
        $zone = strtolower($this->domain($hosting));
        $name = strtolower(rtrim((string) ($details['name'] ?? ''), '.'));
        $type = strtoupper((string) ($details['type'] ?? 'CNAME'));
        $value = rtrim((string) ($details['value'] ?? ''), '.');

        if ($method !== 'dns' || ! str_ends_with($name, ".{$zone}") || $value === '') {
            throw new ProvisioningException("Plesk: the DNS record {$name} is not in the zone {$zone}.");
        }

        $webspace = $this->findWebspace($hosting) ?? throw new ProvisioningException("Plesk has no subscription {$zone}.");
        $siteId = (int) $webspace->id;

        foreach ($this->plesk->call('dns', 'get_rec', ['filter' => ['site-id' => $siteId]], allowNotFound: true) as $record) {
            if (strtoupper((string) $record->data->type) === $type && strtolower(rtrim((string) $record->data->host, '.')) === $name
                && strtolower(rtrim((string) $record->data->value, '.')) === strtolower($value)) {
                return;
            }
        }

        $this->plesk->call('dns', 'add_rec', [
            'site-id' => $siteId,
            'type' => $type,
            'host' => substr($name, 0, -strlen(".{$zone}")),
            'value' => $value,
        ]);
    }

    /**
     * As api-php-lib's Certificate::install() into the subscription's certificate pool, then the subscription's
     * hosting uses it (vrt_hst ssl + certificate_name). The name has part of the certificate's hash, so installing it
     * again finds the same one.
     */
    public function installCertificate(Service $hosting, string $domain, string $certificate, string $privateKey, ?string $caBundle): void
    {
        $zone = $this->domain($hosting);
        $name = 'GaiaCP '.$domain.' '.substr(hash('sha256', $certificate), 0, 8);

        try {
            $this->plesk->call('certificate', 'install', [
                'name' => $name,
                'webspace' => $zone,
                'content' => ['pvt' => $privateKey, 'cert' => $certificate, 'ca' => $caBundle ?: null],
            ]);
        } catch (ProvisioningException $e) {
            if (! str_contains(strtolower($e->getMessage()), 'exist')) {
                throw $e;
            }
        }

        $this->plesk->call('webspace', 'set', [
            'filter' => ['name' => $zone],
            'values' => ['hosting' => ['vrt_hst' => ['property' => [
                ['name' => 'ssl', 'value' => 'true'],
                ['name' => 'certificate_name', 'value' => $name],
            ]]]],
        ]);
    }

    private function findWebspace(Service $service): ?SimpleXMLElement
    {
        return $this->plesk->call('webspace', 'get', [
            'filter' => ['name' => $this->domain($service)],
            'dataset' => ['gen_info' => []],
        ], allowNotFound: true)[0] ?? null;
    }

    private function findCustomerId(Client $client): ?int
    {
        $result = $this->plesk->call('customer', 'get', [
            'filter' => ['external-id' => $this->externalId($client)],
            'dataset' => ['gen_info' => []],
        ], allowNotFound: true)[0] ?? null;

        return $result ? (int) $result->id : null;
    }

    private function createCustomer(Service $service, string $password): int
    {
        $client = $service->client;

        // Same fields, same order as the official plesk/api-php-lib integration tests.
        $result = $this->plesk->call('customer', 'add', [
            'gen_info' => [
                'cname' => $client->company,
                'pname' => $client->name,
                'login' => $service->username,
                'passwd' => $password,
                'email' => $client->email,
                'external-id' => $this->externalId($client),
            ],
        ]);

        return (int) $result[0]->id;
    }

    private function firstSharedIp(): string
    {
        $result = $this->plesk->call('ip', 'get')[0] ?? null;

        foreach ($result->addresses->ip_info ?? [] as $ip) {
            if ((string) $ip->type === 'shared' && filter_var((string) $ip->ip_address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                return (string) $ip->ip_address;
            }
        }

        throw new ProvisioningException("No shared IPv4 address found on {$this->server->name}; set one on the server in GaiaCP.");
    }

    private function setStatus(Service $service, int $status): void
    {
        $this->plesk->call('webspace', 'set', [
            'filter' => ['name' => $this->domain($service)],
            'values' => ['gen_setup' => ['status' => $status]],
        ]);
    }

    private function domain(Service $service): string
    {
        return $service->domain
            ?: throw new ProvisioningException('This service has no domain.');
    }

    private function externalId(Client $client): string
    {
        return "gaiacp-client-{$client->id}";
    }
}
