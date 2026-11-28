<?php

namespace Plugins\Plesk;

use App\Enums\ServiceStatus;
use App\Models\Client;
use App\Models\Server;
use App\Models\Service;
use App\Provisioning\AccountState;
use App\Provisioning\AccountUsage;
use App\Provisioning\DescribesPackages;
use App\Provisioning\HostingProvisioner;
use App\Provisioning\InstallsCertificates;
use App\Provisioning\ListsAccounts;
use App\Provisioning\ManagesWordPress;
use App\Provisioning\OffersShortcuts;
use App\Provisioning\PackageLimits;
use App\Provisioning\PanelAccount;
use App\Provisioning\ProvisioningException;
use App\Provisioning\ReportsLoad;
use App\Provisioning\ServerLoad;
use App\Provisioning\SharesPanelLogin;
use App\Provisioning\WordPressInstall;
use App\Provisioning\WordPressSite;
use App\Provisioning\WpToolkit;
use SimpleXMLElement;

/**
 * Plesk with service plans: one Plesk customer per GaiaCP client (found again
 * through its external-id "gaiacp-client-<id>"), one subscription (webspace)
 * per hosting service, identified by the service's domain. The customer's
 * login and password come from the client's first hosting service on that
 * server; each subscription's system (FTP) user is the service's username.
 */
class PleskProvisioner implements DescribesPackages, HostingProvisioner, InstallsCertificates, ListsAccounts, ManagesWordPress, OffersShortcuts, ReportsLoad, SharesPanelLogin
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

    /**
     * The server statistics (server get stat): the load average in hundredths (`load_avg/l5`, 5 minutes, 15 = 0.15) and the
     * processor in `other/cpu`, whose "(N core(s))" gives the cores when Plesk writes it.
     */
    public function load(): ServerLoad
    {
        $stat = $this->plesk->call('server', 'get', ['stat' => []], timeout: 10)[0]->stat ?? null;
        // The 5-minute load, as often as GaiaCP reads it (the 1-minute one jumps with each short burst)
        $l1 = $stat?->load_avg->l5 ?? $stat?->load_avg->l1 ?? null;
        $cpu = (string) ($stat?->other->cpu ?? '');

        return new ServerLoad(
            $l1 === null || trim((string) $l1) === '' ? null : (float) $l1 / 100,
            preg_match('/(\d+)\s*core/i', $cpu, $m) ? (int) $m[1] : null,
        );
    }

    public function packages(): array
    {
        return array_map('strval', array_keys($this->packageLimits()));
    }

    /** service-plan get: each plan's limits disk_space and max_traffic, in bytes (-1 = unlimited). */
    public function packageLimits(): array
    {
        $packages = [];

        foreach ($this->plesk->call('service-plan', 'get', ['filter' => []], allowNotFound: true, timeout: 8) as $plan) {
            if (($name = (string) $plan->name) === '') {
                continue;
            }

            $limits = [];
            foreach ($plan->limits->limit ?? [] as $limit) {
                $limits[(string) $limit->name] = (int) $limit->value;
            }

            $bytes = fn (string $key) => isset($limits[$key]) && $limits[$key] > 0 ? $limits[$key] : null;
            $packages[$name] = new PackageLimits($bytes('disk_space'), $bytes('max_traffic'), isset($limits['disk_space']), isset($limits['max_traffic']));
        }

        return $packages;
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
        $ips = $this->subscriptionIps();

        // Element order matters to Plesk's schema; status is left out (new subscriptions are active).
        $this->plesk->call('webspace', 'add', [
            'gen_setup' => [
                'name' => $service->domain,
                'owner-id' => $customerId,
                'htype' => 'vrt_hst',
                'ip_address' => $ips,
            ],
            'hosting' => [
                'vrt_hst' => [
                    'property' => [
                        ['name' => 'ftp_login', 'value' => $service->username],
                        ['name' => 'ftp_password', 'value' => $password],
                    ],
                    'ip_address' => $ips,
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

    /**
     * The subscription's system user (FTP / SSH), and the client's Plesk login too when this service made that login
     * (its username is the customer's login), as WHMCS's Plesk module does for the main service. A client's other
     * subscriptions on the server change only their system user.
     */
    public function changePassword(Service $service, string $password): void
    {
        $customer = $this->owner($service);

        // First, since Plesk's password rules are usually stricter for a login: a refusal changes nothing.
        if ($customer !== null && strcasecmp((string) $customer->data->gen_info->login, (string) $service->username) === 0 && $this->belongsToClient($customer, $service)) {
            $this->setCustomerPassword((int) $customer->id, $password);
        }

        // As api-php-lib's Webspace::setProperties().
        $this->plesk->call('webspace', 'set', [
            'filter' => ['name' => $this->domain($service)],
            'values' => ['hosting' => ['vrt_hst' => ['property' => [['name' => 'ftp_password', 'value' => $password]]]]],
        ]);
    }

    /** The Plesk login of the customer who owns the subscription, when it is not the service's username (made by another service, or by hand). */
    public function sharedLogin(Service $service): ?string
    {
        $customer = $this->owner($service);
        $login = (string) ($customer?->data->gen_info->login ?? '');

        return $login !== '' && strcasecmp($login, (string) $service->username) !== 0 && $this->belongsToClient($customer, $service) ? $login : null;
    }

    public function changeSharedLoginPassword(Service $service, string $password): void
    {
        $customer = $this->owner($service)
            ?? throw new ProvisioningException("No Plesk customer owns {$service->domain} on {$this->server->name}.");

        if (! $this->belongsToClient($customer, $service)) {
            throw new ProvisioningException("The Plesk customer that owns {$service->domain} also has another client's subscriptions.");
        }

        $this->setCustomerPassword((int) $customer->id, $password);
    }

    /** The customer who owns the service's subscription; null when the admin or a reseller owns it. */
    private function owner(Service $service): ?SimpleXMLElement
    {
        $webspace = $this->findWebspace($service)
            ?? throw new ProvisioningException("There is no subscription {$service->domain} on {$this->server->name}.");

        return $this->plesk->call('customer', 'get', [
            'filter' => ['id' => (int) $webspace->data->gen_info->{'owner-id'}],
            'dataset' => ['gen_info' => []],
        ], allowNotFound: true)[0] ?? null;
    }

    private function setCustomerPassword(int $customerId, string $password): void
    {
        $this->plesk->call('customer', 'set', [
            'filter' => ['id' => $customerId],
            'values' => ['gen_info' => ['passwd' => $password]],
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

        if (! $this->belongsToClient($customer, $service)) {
            throw new ProvisioningException("The Plesk customer that owns {$service->domain} also has another client's subscriptions.");
        }

        return $this->session((string) $customer->data->gen_info->login, $clientIp);
    }

    /**
     * Whether the client of the service may use the Plesk customer that owns its subscription (log in as it, change its
     * password): one GaiaCP made for this client (its external ID), or one made by hand (or by WHMCS) that owns no
     * subscription GaiaCP bills to another client. A subscription moved to another client (Move to another client)
     * stays under the old client's Plesk customer, so its new client must not get that login.
     */
    private function belongsToClient(SimpleXMLElement $customer, Service $service): bool
    {
        $external = (string) ($customer->data->gen_info->{'external-id'} ?? '');

        if ($external === $this->externalId($service->client)) {
            return true;
        }

        if (str_starts_with($external, 'gaiacp-client-')) {
            return false; // made by GaiaCP for another client
        }

        $domains = array_map(fn (SimpleXMLElement $webspace) => mb_strtolower((string) $webspace->data->gen_info->name), $this->plesk->call('webspace', 'get', [
            'filter' => ['owner-id' => (int) $customer->id],
            'dataset' => ['gen_info' => []],
        ], allowNotFound: true));

        return $domains === [] || ! Service::query()
            ->where('server_id', $this->server->id)
            ->where('client_id', '!=', $service->client_id)
            ->whereIn('domain', $domains)
            ->whereNotIn('status', [ServiceStatus::Terminated, ServiceStatus::Cancelled])
            ->exists();
    }

    /** WP Toolkit's GET /v1/installations, kept to the subscription's domain and its subdomains. */
    public function wordPressSites(Service $service): array
    {
        return WpToolkit::sitesOf($this->plesk->wpToolkit('GET', 'installations')['json'], $this->domain($service));
    }

    /** WP Toolkit's POST /v1/installations/{id}/login, which answers with a redirect to the signed in dashboard. */
    public function wordPressLoginUrl(Service $service, WordPressSite $site, string $clientIp): string
    {
        $answer = $this->plesk->wpToolkit('POST', 'installations/'.rawurlencode($site->id).'/login');

        return WpToolkit::loginLink($answer['location'], $answer['json'])
            ?? throw new ProvisioningException("WP Toolkit gave no login link for {$site->url}.");
    }

    /** WP Toolkit's POST /v1/installations on the subscription's domain; WP Toolkit finishes it in the background. */
    public function installWordPress(Service $service, WordPressInstall $install): void
    {
        $this->plesk->wpToolkit('POST', 'installations', WpToolkit::installBody($this->domain($service), $install), timeout: 120);
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
     * The subscription's disk use (gen_info real_size), this month's traffic (stat, plus its other domains') and counts,
     * its plan's limits     * (-1 = unlimited) and its databases' size (disk_usage dbases), sizes in bytes. Datasets in the schema's order.
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

        $traffic = $number($webspace->data->stat->traffic ?? null);

        return new AccountUsage(
            diskUsed: $number($webspace->data->gen_info->real_size ?? null),
            diskLimit: $limit('disk_space', bytes: true),
            bandwidthUsed: $traffic === null ? null : $traffic + $this->additionalSitesTraffic($this->domain($service)),
            bandwidthLimit: $limit('max_traffic', bytes: true),
            counts: $counts,
        );
    }

    /**
     * This month's traffic of the subscription's other domains (`site get` by parent-name): the subscription's own stat
     * counts only its main domain, so WHMCS's Plesk module adds these too. 0 when there are none or Plesk refuses.
     */
    private function additionalSitesTraffic(string $domain): int
    {
        try {
            $sites = $this->plesk->call('site', 'get', ['filter' => ['parent-name' => $domain], 'dataset' => ['stat' => []]], allowNotFound: true);
        } catch (ProvisioningException) {
            return 0;
        }

        return array_sum(array_map(fn (SimpleXMLElement $site) => is_numeric((string) ($site->data->stat->traffic ?? '')) ? (int) (string) $site->data->stat->traffic : 0, $sites));
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

    /**
     * The addresses of a new subscription: the server's "IP for new subscriptions" or its first shared IPv4, plus its
     * first shared IPv6 when it has one (not a link-local fe80::), as WHMCS gives them. An IPv6 set as the server's
     * address is used alone, as before.
     *
     * @return list<string>
     */
    private function subscriptionIps(): array
    {
        $configured = (string) $this->server->shared_ip;

        if ($configured !== '' && filter_var($configured, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return [$configured];
        }

        if ($configured !== '') {
            // The IPv4 is set by hand: a server that can't list its addresses just gets no IPv6, as before.
            return array_values(array_filter([$configured, rescue(fn () => $this->sharedIps()['ipv6'], null, report: false)]));
        }

        $shared = $this->sharedIps();

        return array_values(array_filter([
            $shared['ipv4'] ?? throw new ProvisioningException("No shared IPv4 address found on {$this->server->name}; set one on the server in GaiaCP."),
            $shared['ipv6'],
        ]));
    }

    /** @return array{ipv4: ?string, ipv6: ?string} the first shared address of each kind (ip get) */
    private function sharedIps(): array
    {
        $result = $this->plesk->call('ip', 'get')[0] ?? null;
        $found = ['ipv4' => null, 'ipv6' => null];

        foreach ($result->addresses->ip_info ?? [] as $info) {
            $ip = (string) $info->ip_address;

            if ((string) $info->type !== 'shared') {
                continue;
            }

            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $found['ipv4'] ??= $ip;
            } elseif (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) && ! preg_match('/^fe[89ab]/i', $ip) && $ip !== '::1') {
                $found['ipv6'] ??= $ip;
            }
        }

        return $found;
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
