<?php

namespace Plugins\CyberPanel;

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
 * CyberPanel: one website per hosting service (the service's domain), owned by a CyberPanel user named after the
 * service's username, created with it by the server's `admin` login on one of its packages. The owner's password is
 * the CyberPanel login; FTP, databases and mail keep their own. Suspending suspends the website.
 */
class CyberPanelProvisioner implements HostingProvisioner, ListsAccounts
{
    /** fetchWebsites answers ten websites a page. */
    private const PAGE_SIZE = 10;

    public function __construct(
        private CyberPanelClient $cp,
        private Server $server,
    ) {}

    public function testConnection(): string
    {
        $version = $this->cp->api('cyberPanelVersion', [], 'getVersion');

        if ($this->server->username !== 'admin') {
            throw new ProvisioningException('CyberPanel lets only its admin user read the websites (cloud API). Use the admin login on this server.');
        }

        // The cloud API too: account states, the website list and usage come from it.
        $this->cp->cloud('verifyLogin');

        return trim(sprintf('CyberPanel %s%s', $version['currentVersion'] ?? '', filled($version['build'] ?? null) ? '.'.$version['build'] : ''));
    }

    public function packages(): array
    {
        $packages = $this->cp->api('listPackage', [], null, timeout: 8);

        return array_values(array_filter(array_map('strval', array_filter($packages, 'is_scalar')), fn (string $name) => $name !== ''));
    }

    public function accountState(Service $service): AccountState
    {
        $site = $this->site($service);

        return match (true) {
            $site === null => AccountState::Missing,
            strcasecmp((string) ($site['state'] ?? ''), 'Suspended') === 0 => AccountState::Suspended,
            default => AccountState::Active,
        };
    }

    /** Every website on the server, as its owner's account. A user with two websites shows twice. */
    public function accounts(): array
    {
        return array_map(fn (array $site) => new PanelAccount(
            username: (string) ($site['admin'] ?? ''),
            domain: filled($site['domain'] ?? null) ? (string) $site['domain'] : null,
            package: filled($site['package'] ?? null) ? (string) $site['package'] : null,
            suspended: strcasecmp((string) ($site['state'] ?? ''), 'Suspended') === 0,
        ), $this->websites(timeout: 60));
    }

    public function accountDomain(Service $service): ?string
    {
        $domain = (string) ($this->site($service)['domain'] ?? '');

        return $service->domain && $domain === DomainName::toAscii($service->domain) ? $service->domain : ($domain ?: null);
    }

    public function createAccount(Service $service, string $password): void
    {
        if (! $service->domain || ! $service->package || ! $service->username) {
            throw new ProvisioningException('A CyberPanel website needs a domain, a package and a username.');
        }

        // CyberPanel finishes the website in the background; an existing owner user is reused as it is.
        $this->cp->api('createWebsite', [
            'domainName' => DomainName::toAscii($service->domain),
            'ownerEmail' => $service->client->email,
            'packageName' => $service->package,
            'websiteOwner' => $this->username($service),
            'ownerPassword' => $password,
            'phpSelection' => (string) (config('gaiacp.cyberpanel.php_version') ?: 'PHP 8.2'),
        ], 'createWebSiteStatus', secrets: [$password], timeout: 180);
    }

    public function suspendAccount(Service $service, string $reason): void
    {
        // CyberPanel takes no reason; it stays in GaiaCP.
        $this->cp->api('submitWebsiteStatus', ['websiteName' => $this->websiteName($service), 'state' => 'Suspend'], 'websiteStatus', timeout: 120);
    }

    public function unsuspendAccount(Service $service): void
    {
        $this->cp->api('submitWebsiteStatus', ['websiteName' => $this->websiteName($service), 'state' => 'Unsuspend'], 'websiteStatus', timeout: 120);
    }

    /** The website, then its owner user once it owns no other website (CyberPanel may have removed it already). */
    public function terminateAccount(Service $service): void
    {
        $username = $this->username($service);

        $this->cp->api('deleteWebsite', ['domainName' => $this->websiteName($service)], 'websiteDeleteStatus', timeout: 300);

        if ($this->owned($username) !== []) {
            return;
        }

        try {
            $this->cp->api('getUserInfo', ['username' => $username], 'status');
        } catch (ProvisioningException) {
            return; // no such user left
        }

        $this->cp->api('submitUserDeletion', ['accountUsername' => $username], 'deleteStatus', timeout: 120);
    }

    public function changePackage(Service $service, string $package): void
    {
        $this->cp->api('changePackageAPI', ['websiteName' => $this->websiteName($service), 'packageName' => $package], 'changePackage', timeout: 120);
    }

    /** The owner's CyberPanel login. */
    public function changePassword(Service $service, string $password): void
    {
        $this->cp->api('changeUserPassAPI', ['websiteOwner' => $this->username($service), 'ownerPassword' => $password], 'changeStatus', secrets: [$password]);
    }

    /** CyberPanel's login page: its one-time login (cloudAPI/access) needs the owner's lasting API token in the link. */
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
     * fetchWebsiteData: disk (`diskInMB` of `diskInMBTotal`) and this month's bandwidth (`bwInMB` of `bwInMBTotal`)
     * in MB, databases (`dbUsed` of `dbAllowed`); a limit of 0 is unlimited.
     */
    public function usage(Service $service): AccountUsage
    {
        $site = $this->site($service)
            ?? throw new ProvisioningException("There is no CyberPanel website for {$service->username} on {$this->server->name}.");
        $data = $this->cp->cloud('fetchWebsiteData', ['domainName' => (string) $site['domain']], timeout: 60);

        $number = fn (mixed $value) => is_numeric($value) ? (int) $value : null;
        $limit = fn (mixed $value) => is_numeric($value) && (float) $value > 0 ? (int) $value : null;
        $megabytes = fn (?int $value) => $value === null ? null : $value * 1024 ** 2;

        return new AccountUsage(
            diskUsed: is_numeric($data['diskInMB'] ?? null) ? (int) round((float) $data['diskInMB'] * 1024 ** 2) : null,
            diskLimit: $megabytes($limit($data['diskInMBTotal'] ?? null)),
            bandwidthUsed: is_numeric($data['bwInMB'] ?? null) ? (int) round((float) $data['bwInMB'] * 1024 ** 2) : null,
            bandwidthLimit: $megabytes($limit($data['bwInMBTotal'] ?? null)),
            counts: array_key_exists('dbUsed', $data) ? ['databases' => [$number($data['dbUsed']), $limit($data['dbAllowed'] ?? null)]] : [],
        );
    }

    /**
     * The service's website: the one its owner has with the service's domain, else the owner's first.
     *
     * @return array<string, mixed>|null
     */
    private function site(Service $service): ?array
    {
        $owned = $this->owned($this->username($service));
        $domain = $service->domain ? DomainName::toAscii($service->domain) : null;

        foreach ($owned as $site) {
            if ($domain !== null && strcasecmp((string) ($site['domain'] ?? ''), $domain) === 0) {
                return $site;
            }
        }

        return $owned[0] ?? null;
    }

    /** @return list<array<string, mixed>> the websites this CyberPanel user owns */
    private function owned(string $username): array
    {
        return array_values(array_filter($this->websites(), fn (array $site) => strcasecmp((string) ($site['admin'] ?? ''), $username) === 0));
    }

    /**
     * Every website (cloud API fetchWebsites, page by page): domain, admin (the owner), package, state, diskUsed.
     *
     * @return list<array<string, mixed>>
     */
    private function websites(int $timeout = 30): array
    {
        $sites = [];

        for ($page = 1; $page <= 1000; $page++) {
            $reply = $this->cp->cloud('fetchWebsites', ['page' => $page], $timeout);
            // The list comes as a JSON string inside the JSON reply.
            $data = is_string($reply['data'] ?? null) ? json_decode($reply['data'], true) : ($reply['data'] ?? []);
            $batch = array_values(array_filter(is_array($data) ? $data : [], 'is_array'));
            $sites = [...$sites, ...$batch];

            if (count($batch) < self::PAGE_SIZE || $page >= max(1, count((array) ($reply['pagination'] ?? [])))) {
                break;
            }
        }

        return $sites;
    }

    /** The website to act on: the service's own, else its domain as it is. */
    private function websiteName(Service $service): string
    {
        $domain = $this->site($service)['domain'] ?? ($service->domain ? DomainName::toAscii($service->domain) : null);

        return $domain ?: throw new ProvisioningException('This service has no CyberPanel website.');
    }

    private function panelUrl(): string
    {
        return sprintf('https://%s:%d/', $this->server->hostname, $this->server->port);
    }

    private function username(Service $service): string
    {
        return $service->username
            ?: throw new ProvisioningException('This service has no CyberPanel username.');
    }
}
