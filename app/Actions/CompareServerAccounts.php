<?php

namespace App\Actions;

use App\Enums\ServiceStatus;
use App\Enums\ServiceType;
use App\Models\Server;
use App\Models\Service;
use App\Provisioning\ListsAccounts;
use App\Provisioning\PanelAccount;
use App\Provisioning\ProvisionerFactory;
use App\Provisioning\ProvisioningException;
use App\Support\DomainName;
use Illuminate\Support\Collection;

/**
 * Servers → Compare with the server, like WHMCS's Server Sync: the accounts the panel lists against the hosting
 * services GaiaCP has on that server, matched by username, else by domain. Read only; nothing is changed.
 *
 * - on the server only: an account GaiaCP doesn't bill (with the terminated or cancelled service it once was, if any),
 *   or bills on another server (`elsewhere`: left behind after a move), or the admin marked "Not billed on purpose"
 *   (`ignored`, Server::$ignored_accounts)
 * - missing: an active or suspended service whose account the server doesn't have
 * - different: matched, but suspended on one side only, or another package or domain
 *
 * Pending services aren't set up yet, so they are left out.
 */
class CompareServerAccounts
{
    public function __construct(private ProvisionerFactory $provisioners) {}

    /**
     * @return array{
     *     onServerOnly: list<array{account: PanelAccount, former: ?Service, elsewhere: ?Service, ignored: bool, note: ?string}>,
     *     missing: list<Service>,
     *     different: list<array{account: PanelAccount, service: Service, differences: list<string>}>,
     *     matching: int,
     * }
     *
     * @throws ProvisioningException when the panel can't be read or can't list its accounts
     */
    public function handle(Server $server): array
    {
        $panel = $this->provisioners->forServer($server);

        if (! $panel instanceof ListsAccounts) {
            throw new ProvisioningException('This control panel cannot list its accounts.');
        }

        $accounts = $panel->accounts();

        $services = Service::query()
            ->with('client')
            ->where('server_id', $server->id)
            ->where('type', ServiceType::Hosting)
            ->whereIn('status', [ServiceStatus::Active, ServiceStatus::Suspended, ServiceStatus::Terminated, ServiceStatus::Cancelled])
            ->orderByDesc('id')
            ->get();

        [$live, $ended] = $services->partition(fn (Service $s) => in_array($s->status, [ServiceStatus::Active, ServiceStatus::Suspended], true));

        // Billed on another server: an account left behind after moving it there
        $otherServers = Service::query()
            ->with(['client', 'server'])
            ->where('server_id', '!=', $server->id)
            ->where('type', ServiceType::Hosting)
            ->whereIn('status', [ServiceStatus::Active, ServiceStatus::Suspended])
            ->orderByDesc('id')
            ->get();

        $ignored = $server->ignored_accounts ?? [];

        $result = ['onServerOnly' => [], 'missing' => [], 'different' => [], 'matching' => 0];
        $matched = [];

        foreach ($accounts as $account) {
            $service = self::find($live, $account, $matched);

            if ($service === null) {
                $result['onServerOnly'][] = [
                    'account' => $account,
                    'former' => self::find($ended, $account),
                    'elsewhere' => self::find($otherServers, $account),
                    'ignored' => array_key_exists($account->username, $ignored),
                    'note' => $ignored[$account->username] ?? null,
                ];

                continue;
            }

            $matched[] = $service->id;
            $differences = self::differences($service, $account);

            if ($differences === []) {
                $result['matching']++;
            } else {
                $result['different'][] = ['account' => $account, 'service' => $service, 'differences' => $differences];
            }
        }

        $result['missing'] = $live->reject(fn (Service $s) => in_array($s->id, $matched, true))->values()->all();

        return $result;
    }

    /**
     * What needs an admin's eye, keyed so that the nightly run (gaiacp:compare-servers) can tell what is new: accounts
     * not billed anywhere that are running (suspended ones, moved ones and the ones marked "Not billed on purpose" are
     * left out), missing accounts and differences.
     *
     * @param  array<string, mixed>  $result  from handle()
     * @return array<string, string> key => what it is, in English (the activity log)
     */
    public static function problems(array $result): array
    {
        $problems = [];

        foreach ($result['onServerOnly'] as $row) {
            if (! $row['ignored'] && ! $row['elsewhere'] && ! $row['account']->suspended) {
                $account = $row['account'];
                $problems['only:'.$account->username] = "{$account->username}".($account->domain ? " ({$account->domain})" : '').' is on the server but not billed';
            }
        }

        foreach ($result['missing'] as $service) {
            $problems["missing:{$service->id}"] = "{$service->label}".($service->username ? " ({$service->username})" : '').' is missing on the server';
        }

        foreach ($result['different'] as $row) {
            $problems["different:{$row['service']->id}:".implode(',', $row['differences'])] = "{$row['service']->label}: ".implode(', ', $row['differences']);
        }

        return $problems;
    }

    /**
     * The service of an account: same username, else same domain (Plesk names a subscription by its domain).
     *
     * @param  Collection<int, Service>  $services
     * @param  list<int>  $skip  services already matched to another account
     */
    private static function find(Collection $services, PanelAccount $account, array $skip = []): ?Service
    {
        $candidates = $services->reject(fn (Service $s) => in_array($s->id, $skip, true));

        return $candidates->first(fn (Service $s) => filled($s->username) && strcasecmp($s->username, $account->username) === 0)
            ?? ($account->domain === null ? null : $candidates->first(fn (Service $s) => filled($s->domain) && self::sameDomain($s->domain, $account->domain)));
    }

    /** @return list<string> what differs, in English (translated where shown) */
    private static function differences(Service $service, PanelAccount $account): array
    {
        $differences = [];

        if ($account->suspended !== ($service->status === ServiceStatus::Suspended)) {
            $differences[] = $account->suspended ? 'Suspended on the server, active in GaiaCP' : 'Active on the server, suspended in GaiaCP';
        }

        if (filled($service->package) && filled($account->package) && strcasecmp($service->package, $account->package) !== 0) {
            $differences[] = 'Package';
        }

        if (filled($service->domain) && filled($account->domain) && ! self::sameDomain($service->domain, $account->domain)) {
            $differences[] = 'Domain';
        }

        return $differences;
    }

    private static function sameDomain(string $a, string $b): bool
    {
        return DomainName::toAscii($a) === DomainName::toAscii($b);
    }
}
