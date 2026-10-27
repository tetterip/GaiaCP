<?php

namespace Plugins\Cpanel;

use App\Models\Service;
use App\Provisioning\AccountState;
use App\Provisioning\AccountUsage;
use App\Provisioning\HostingProvisioner;
use App\Provisioning\InstallsCertificates;
use App\Provisioning\ListsAccounts;
use App\Provisioning\OffersShortcuts;
use App\Provisioning\PanelAccount;
use App\Provisioning\ProvisioningException;
use App\Support\DomainName;

class CpanelProvisioner implements HostingProvisioner, InstallsCertificates, ListsAccounts, OffersShortcuts
{
    public function __construct(private WhmClient $whm) {}

    public function testConnection(): string
    {
        $data = $this->whm->call('version');

        return 'WHM '.($data['version'] ?? 'unknown version');
    }

    public function packages(): array
    {
        // Also used for form suggestions, so fail fast.
        $data = $this->whm->call('listpkgs', timeout: 8);

        return collect($data['pkg'] ?? [])->pluck('name')->filter()->values()->all();
    }

    public function accountState(Service $service): AccountState
    {
        $account = $this->findAccount($service);

        return match (true) {
            $account === null => AccountState::Missing,
            (bool) $account['suspended'] => AccountState::Suspended,
            default => AccountState::Active,
        };
    }

    /** Every account on the server: WHM's listaccts with no search. */
    public function accounts(): array
    {
        $data = $this->whm->call('listaccts', ['want' => 'user,domain,plan,suspended'], timeout: 60);

        return collect($data['acct'] ?? [])
            ->map(fn (array $acct) => new PanelAccount(
                username: (string) $acct['user'],
                domain: filled($acct['domain'] ?? null) ? (string) $acct['domain'] : null,
                package: filled($acct['plan'] ?? null) ? (string) $acct['plan'] : null,
                suspended: (bool) ($acct['suspended'] ?? false),
            ))
            ->values()
            ->all();
    }

    public function accountDomain(Service $service): ?string
    {
        return $this->findAccount($service)['domain'] ?? null;
    }

    /** @return array{user: string, domain: string, suspended: int|bool}|null */
    private function findAccount(Service $service): ?array
    {
        $username = $this->username($service);

        $data = $this->whm->call('listaccts', [
            'search' => '^'.preg_quote($username).'$',
            'searchtype' => 'user',
            'searchmethod' => 'regex',
            'want' => 'user,domain,suspended',
        ]);

        return collect($data['acct'] ?? [])->firstWhere('user', $username);
    }

    public function createAccount(Service $service, string $password): void
    {
        if (! $service->domain || ! $service->package) {
            throw new ProvisioningException('A cPanel account needs a domain and a package.');
        }

        // Account creation can take a minute on a busy server.
        $this->whm->call('createacct', [
            'username' => $this->username($service),
            'domain' => DomainName::toAscii($service->domain),
            'plan' => $service->package,
            'password' => $password,
            'contactemail' => $service->client->email,
        ], timeout: 180);
    }

    /** WHM's create_user_session: a cPanel session for the account's user, opened by its URL. */
    public function loginUrl(Service $service, string $clientIp): string
    {
        return $this->session($service);
    }

    /** cPanel app names (create_user_session's `app`), as WHMCS's cPanel module opens them. */
    public const SHORTCUTS = [
        'email' => ['Email accounts', 'heroicon-o-envelope', 'Email_Accounts'],
        'files' => ['File Manager', 'heroicon-o-folder-open', 'FileManager_Home'],
        'databases' => ['MySQL databases', 'heroicon-o-circle-stack', 'Database_MySQL'],
        'phpmyadmin' => ['phpMyAdmin', 'heroicon-o-table-cells', 'Database_phpMyAdmin'],
        'statistics' => ['Statistics', 'heroicon-o-chart-bar', 'Stats_AWStats'],
        'backups' => ['Backups', 'heroicon-o-archive-box', 'Backups_Home'],
    ];

    public function shortcuts(): array
    {
        return array_map(fn (array $shortcut) => ['label' => __($shortcut[0]), 'icon' => $shortcut[1]], self::SHORTCUTS);
    }

    public function shortcutUrl(Service $service, string $key, string $clientIp): string
    {
        $app = self::SHORTCUTS[$key][2] ?? throw new ProvisioningException("cPanel has no shortcut {$key}.");

        return $this->session($service, $app);
    }

    /** WHM's create_user_session into the account's cPanel, opening $app when given. */
    private function session(Service $service, ?string $app = null): string
    {
        $data = $this->whm->call('create_user_session', array_filter(['user' => $this->username($service), 'service' => 'cpaneld', 'app' => $app]));

        return (string) ($data['url'] ?? '') ?: throw new ProvisioningException('WHM create_user_session returned no URL.');
    }

    /** WHM's create_user_session for the server's own user (root or a reseller) into WHM itself. */
    public function adminLoginUrl(string $clientIp): string
    {
        $data = $this->whm->call('create_user_session', ['user' => $this->whm->username(), 'service' => 'whostmgrd']);

        return (string) ($data['url'] ?? '') ?: throw new ProvisioningException('WHM create_user_session returned no URL.');
    }

    /** cPanel StatsBar names => AccountUsage::COUNTS keys ("aliases" is the old name of parked domains). */
    private const STATS = [
        'emailaccounts' => 'email_accounts',
        'sqldatabases' => 'databases',
        'mysqldiskusage' => 'database_disk',
        'addondomains' => 'addon_domains',
        'parkeddomains' => 'parked_domains',
        'aliases' => 'parked_domains',
        'subdomains' => 'subdomains',
    ];

    /**
     * Disk from WHM accountsummary ("150M" of "1000M" or "unlimited"), this month's bandwidth from showbw (bytes), and
     * the rest from the account's own StatsBar (UAPI through WHM uapi_cpanel), which is left out if it fails.
     */
    public function usage(Service $service): AccountUsage
    {
        $username = $this->username($service);

        $account = $this->whm->call('accountsummary', ['user' => $username])['acct'][0] ?? null;

        if ($account === null) {
            throw new ProvisioningException("There is no cPanel account {$username}.");
        }

        $bandwidth = collect($this->whm->call('showbw', [
            'searchtype' => 'user',
            'search' => '^'.preg_quote($username).'$',
        ])['acct'] ?? [])->firstWhere('user', $username);

        return new AccountUsage(
            diskUsed: self::megabytes($account['diskused'] ?? null),
            diskLimit: self::megabytes($account['disklimit'] ?? null) ?: null,
            bandwidthUsed: is_numeric($bandwidth['totalbytes'] ?? null) ? (int) $bandwidth['totalbytes'] : null,
            bandwidthLimit: is_numeric($bandwidth['limit'] ?? null) && (int) $bandwidth['limit'] > 0 ? (int) $bandwidth['limit'] : null,
            counts: $this->stats($username),
        );
    }

    /** @return array<string, array{0: ?int, 1: ?int}> */
    private function stats(string $username): array
    {
        try {
            $items = $this->whm->call('uapi_cpanel', [
                'cpanel.user' => $username,
                'cpanel.module' => 'StatsBar',
                'cpanel.function' => 'get_stats',
                'display' => implode('|', array_keys(self::STATS)),
            ])['uapi']['data'] ?? [];
        } catch (ProvisioningException) {
            return [];
        }

        $counts = [];
        foreach (is_array($items) ? $items : [] as $item) {
            $key = self::STATS[$item['name'] ?? $item['id'] ?? ''] ?? null;

            if ($key === null) {
                continue;
            }

            // Sizes come in MB (or the item's units); "unlimited", "∞" and empty limits are unlimited.
            $value = fn (mixed $raw) => $key === 'database_disk'
                ? (is_numeric($raw) ? self::megabytes($raw.substr((string) ($item['units'] ?? 'M'), 0, 1)) : null)
                : (is_numeric($raw) ? (int) $raw : null);

            $counts[$key] = [$value($item['_count'] ?? $item['count'] ?? null), $value($item['_max'] ?? $item['max'] ?? null)];
        }

        return $counts;
    }

    /** "150M", "1.5G", "512K" (megabytes when no unit) in bytes; "unlimited" and "none" as null. */
    private static function megabytes(mixed $value): ?int
    {
        if (! preg_match('/^\s*([\d.]+)\s*([KMGT])?/i', (string) $value, $m)) {
            return null;
        }

        $power = ['K' => 1, 'M' => 2, 'G' => 3, 'T' => 4][strtoupper(($m[2] ?? '') ?: 'M')];

        return (int) round((float) $m[1] * 1024 ** $power);
    }

    /** A file in the website's folder: Sectigo and the other authorities read it over HTTP (or HTTPS). */
    public function certificateValidationMethods(): array
    {
        return ['http', 'https'];
    }

    /**
     * Saves the validation file under the domain's document root (UAPI DomainInfo::single_domain_data), making the
     * /.well-known/pki-validation folders first (cPanel API 2 Fileman::mkdir; "already exists" is fine), then
     * UAPI Fileman::save_file_content as the account's user.
     */
    public function placeCertificateValidation(Service $hosting, string $method, array $details): void
    {
        $username = $this->username($hosting);
        $path = (string) ($details['path'] ?? '');

        if (! preg_match('#^/\.well-known/pki-validation/[A-Za-z0-9_-]+\.txt$#', $path) || str_contains($path, '..')) {
            throw new ProvisioningException('cPanel: the validation file must be a .txt file under /.well-known/pki-validation/.');
        }

        $root = rtrim($this->documentRoot($hosting), '/');
        $parent = $root;

        foreach (array_filter(explode('/', dirname($path))) as $folder) {
            rescue(fn () => $this->whm->call('cpanel', [
                'cpanel_jsonapi_user' => $username,
                'cpanel_jsonapi_apiversion' => 2,
                'cpanel_jsonapi_module' => 'Fileman',
                'cpanel_jsonapi_func' => 'mkdir',
                'path' => $parent,
                'name' => $folder,
            ]), report: false);
            $parent .= '/'.$folder;
        }

        $this->uapi($username, 'Fileman', 'save_file_content', [
            'dir' => $parent,
            'file' => basename($path),
            'content' => (string) ($details['content'] ?? ''),
        ]);
    }

    /** UAPI SSL::install_ssl as the account's user: installs the certificate and uses it for the domain's website. */
    public function installCertificate(Service $hosting, string $domain, string $certificate, string $privateKey, ?string $caBundle): void
    {
        $this->uapi($this->username($hosting), 'SSL', 'install_ssl', array_filter([
            'domain' => DomainName::toAscii($domain),
            'cert' => $certificate,
            'key' => $privateKey,
            'cabundle' => $caBundle,
        ], fn ($value) => filled($value)));
    }

    /** The folder the domain's website is served from. */
    private function documentRoot(Service $hosting): string
    {
        $data = $this->uapi($this->username($hosting), 'DomainInfo', 'single_domain_data', ['domain' => DomainName::toAscii((string) $hosting->domain)]);

        return (string) ($data['documentroot'] ?? '') ?: throw new ProvisioningException("cPanel did not say where the website of {$hosting->domain} is.");
    }

    /**
     * A UAPI function of the account's user, through WHM's uapi_cpanel; throws with cPanel's errors when it fails.
     *
     * @param  array<string, scalar>  $params
     */
    private function uapi(string $username, string $module, string $function, array $params = []): mixed
    {
        $result = $this->whm->call('uapi_cpanel', ['cpanel.user' => $username, 'cpanel.module' => $module, 'cpanel.function' => $function, ...$params], timeout: 60)['uapi'] ?? [];

        if ((int) ($result['status'] ?? 0) !== 1) {
            $errors = trim(implode(' ', array_map('strval', (array) ($result['errors'] ?? [])))) ?: 'unknown error';

            throw new ProvisioningException("cPanel {$module}::{$function}: {$errors}");
        }

        return $result['data'] ?? null;
    }

    public function suspendAccount(Service $service, string $reason): void
    {
        $this->whm->call('suspendacct', ['user' => $this->username($service), 'reason' => $reason]);
    }

    public function unsuspendAccount(Service $service): void
    {
        $this->whm->call('unsuspendacct', ['user' => $this->username($service)]);
    }

    public function terminateAccount(Service $service): void
    {
        $this->whm->call('removeacct', ['username' => $this->username($service)], timeout: 180);
    }

    public function changePackage(Service $service, string $package): void
    {
        $this->whm->call('changepackage', ['user' => $this->username($service), 'pkg' => $package]);
    }

    public function changePassword(Service $service, string $password): void
    {
        $this->whm->call('passwd', ['user' => $this->username($service), 'password' => $password]);
    }

    private function username(Service $service): string
    {
        return $service->username
            ?: throw new ProvisioningException('This service has no cPanel username.');
    }
}
