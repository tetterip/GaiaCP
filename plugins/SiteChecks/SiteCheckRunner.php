<?php

namespace Plugins\SiteChecks;

use App\Enums\ServiceStatus;
use App\Enums\ServiceType;
use App\Models\ActivityLog;
use App\Models\Server;
use App\Models\Service;
use App\Providers\AppServiceProvider;
use App\Support\DomainName;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Checks each active hosting account's domain: does it point to its server (the server's IP address and shared IP
 * fields, and what its hostname points to; Cloudflare nameservers = proxied, not compared), and does it answer HTTPS
 * with a trusted certificate for its name that is not about to expire (only when it points to its server: elsewhere
 * the answer would be another server's). One site_checks row per service. A problem not told before goes to the bell
 * once (site.problems, one entry per run); fixed and back again is new again. Safe to re-run.
 */
class SiteCheckRunner
{
    public const CLOUDFLARE_NAMESERVERS = '.ns.cloudflare.com';

    /** @var array<int, list<string>> server id => its addresses, for one run */
    private array $serverAddresses = [];

    public function __construct(private SiteProbe $probe) {}

    /** @return Builder<Service> the services that have a site to check */
    public static function services(): Builder
    {
        return Service::query()
            ->with(['server', 'client'])
            ->where('type', ServiceType::Hosting)
            ->where('status', ServiceStatus::Active)
            ->whereNotNull('server_id')
            ->whereNotNull('domain')
            ->where('domain', '!=', '');
    }

    /** @return array{sites: int, problems: int, new: list<string>} */
    public function run(): array
    {
        $summary = ['sites' => 0, 'problems' => 0, 'new' => []];
        $checked = [];

        foreach (self::services()->orderBy('domain')->get() as $service) {
            [$check, $new] = $this->check($service);
            $checked[] = $service->id;
            $summary['sites']++;
            $summary['problems'] += $check->problems() === [] ? 0 : 1;
            array_push($summary['new'], ...$new);
        }

        // Suspended, terminated, moved off its domain: nothing to check any more
        SiteCheck::query()->whereNotIn('service_id', $checked ?: [0])->delete();
        $this->tell($summary['new']);

        return $summary;
    }

    /**
     * Checks one site and keeps the result.
     *
     * @return array{0: SiteCheck, 1: list<string>} the check, and its problems not told before (English, for the log)
     */
    public function check(Service $service): array
    {
        $domain = DomainName::toAscii((string) $service->domain);
        $addresses = $this->probe->addresses($domain);
        $dns = $this->dns($service->server, $domain, $addresses);

        $ssl = ['status' => SslStatus::Skipped, 'valid_to' => null, 'issuer' => null, 'error' => null];
        if (! $dns->isProblem()) {
            $ssl = $this->ssl($domain);
        }

        $check = SiteCheck::query()->firstOrNew(['service_id' => $service->id]);
        $check->fill([
            'domain' => $domain,
            'dns_status' => $dns,
            'addresses' => $addresses,
            'ssl_status' => $ssl['status'],
            'ssl_valid_to' => $ssl['valid_to'],
            'ssl_issuer' => $ssl['issuer'] !== null ? Str::limit($ssl['issuer'], 250) : null,
            'ssl_error' => $ssl['error'] !== null ? Str::limit($ssl['error'], 250) : null,
            'checked_at' => now(),
        ]);

        $problems = $check->problems();
        $new = array_values(array_diff($problems, $check->alerted ?? []));
        $check->alerted = $problems;
        $check->save();

        return [$check, array_map(fn (string $problem) => self::describe($check, $problem), $new)];
    }

    /** One bell entry for everything new in a run. */
    public function tell(array $new): void
    {
        if ($new === []) {
            return;
        }

        ActivityLog::record('site.problems', Str::limit(implode('; ', $new), 250), properties: [
            'count' => count($new),
            'items' => $new,
            'url' => SiteChecksPage::getUrl(panel: 'admin'),
        ]);
    }

    /** @param  list<string>  $addresses */
    private function dns(?Server $server, string $domain, array $addresses): DnsStatus
    {
        if ($addresses === []) {
            return DnsStatus::None;
        }

        $ours = $server ? $this->serverAddresses($server) : [];

        return match (true) {
            array_intersect($addresses, $ours) !== [] => DnsStatus::Ours,
            collect($this->probe->nameservers($domain))->contains(fn (string $ns) => str_ends_with($ns, self::CLOUDFLARE_NAMESERVERS)) => DnsStatus::Cloudflare,
            $ours === [] => DnsStatus::Unknown,
            default => DnsStatus::Elsewhere,
        };
    }

    /** @return list<string> */
    private function serverAddresses(Server $server): array
    {
        return $this->serverAddresses[$server->id] ??= collect(preg_split('/[\s,]+/', "{$server->ip_address} {$server->shared_ip}"))
            ->filter(fn ($ip) => filter_var($ip, FILTER_VALIDATE_IP))
            ->merge(filled($server->hostname) ? $this->probe->addresses((string) $server->hostname) : [])
            ->unique()->values()->all();
    }

    /** @return array{status: SslStatus, valid_to: ?CarbonImmutable, issuer: ?string, error: ?string} */
    private function ssl(string $domain): array
    {
        $certificate = $this->probe->certificate($domain);
        $validTo = $certificate['valid_to'];

        $status = match (true) {
            $validTo === null => SslStatus::None,
            $validTo->isPast() => SslStatus::Expired,
            ! self::covers($certificate['names'], $domain) => SslStatus::WrongName,
            ! $certificate['trusted'] => SslStatus::Untrusted,
            $validTo->lt(now()->addDays((int) config('gaiacp.site_checks.ssl_warn_days', 14))) => SslStatus::Expiring,
            default => SslStatus::Valid,
        };

        return ['status' => $status, 'valid_to' => $validTo, 'issuer' => $certificate['issuer'], 'error' => $certificate['error']];
    }

    /** @param  list<string>  $names  the certificate's names, wildcards included */
    public static function covers(array $names, string $domain): bool
    {
        foreach ($names as $name) {
            if ($name === $domain || (str_starts_with($name, '*.') && substr_count($domain, '.') === substr_count($name, '.') && str_ends_with($domain, substr($name, 1)))) {
                return true;
            }
        }

        return false;
    }

    private static function describe(SiteCheck $check, string $problem): string
    {
        return $check->domain.': '.match ($problem) {
            'dns:elsewhere' => 'points to '.implode(', ', $check->addresses ?? []).', not its server',
            'dns:none' => 'has no DNS records',
            'ssl:expiring' => 'certificate expires on '.$check->ssl_valid_to?->format(AppServiceProvider::dateFormat()),
            'ssl:expired' => 'certificate expired on '.$check->ssl_valid_to?->format(AppServiceProvider::dateFormat()),
            'ssl:wrong_name' => 'certificate is for another name',
            'ssl:untrusted' => 'certificate is not trusted',
            'ssl:none' => 'no HTTPS',
            default => $problem,
        };
    }
}
