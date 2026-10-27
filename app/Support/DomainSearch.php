<?php

namespace App\Support;

use App\Enums\DomainAvailability;
use App\Models\Registrar;
use App\Models\Tld;
use App\Provisioning\ChecksAvailability;
use App\Provisioning\ProvisioningException;
use App\Provisioning\RegistrarFactory;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * The client area's domain search: the name a client typed with each TLD sold for registration (Settings → Domain
 * pricing), and whether each is free. It asks the TLD's registrar when its plugin can tell (ChecksAvailability), else
 * the registry's RDAP server (from IANA's list), else DNS: a domain with nameservers is taken, one without is only
 * probably free. Answers are kept for 10 minutes; ordering checks the domain again when it is
 * registered.
 */
class DomainSearch
{
    /** IANA's list of RDAP servers by TLD. */
    public const BOOTSTRAP_URL = 'https://data.iana.org/rdap/dns.json';

    /** The most domains one search checks: the one typed, then the other TLDs. */
    public const MAX_RESULTS = 10;

    /** How many domains a client (or a visitor's address) may look up a minute, in searches and order forms together. */
    public const PER_MINUTE = 20;

    /** How long an answer is kept, in seconds. */
    private const KEEP = 600;

    private static ?Closure $nameservers = null;

    public function __construct(private RegistrarFactory $registrars) {}

    /** Tests: what DNS answers for a domain's nameservers (fn (string $ascii): list<string>). */
    public static function resolveNameserversUsing(?Closure $resolver): void
    {
        self::$nameservers = $resolver;
    }

    /** @return list<Tld> the TLDs sold for registration, as listed under Settings → Domain pricing */
    public static function tlds(): array
    {
        return Tld::query()->active()->orderBy('id')->get()->filter(fn (Tld $tld) => $tld->terms('register') !== [])->values()->all();
    }

    /**
     * What a search looks for: "myshop" or "myshop.gr" (also typed as a web address) gives myshop with each TLD sold,
     * the one typed first. $unsupported is the TLD typed when it is not sold.
     *
     * @return array{domains: list<string>, requested: ?string, unsupported: ?string}
     */
    public static function candidates(string $query): array
    {
        $query = preg_replace('#^(https?://)?(www\.)?#i', '', trim($query));
        $query = DomainName::normalize(strtok($query, '/?#') ?: '');
        $tlds = self::tlds();
        $none = ['domains' => [], 'requested' => null, 'unsupported' => null];

        if ($query === '' || $tlds === []) {
            return $none;
        }

        $name = explode('.', $query)[0];
        $suffix = mb_substr($query, mb_strlen($name));
        $sold = $suffix !== '' && collect($tlds)->contains(fn (Tld $tld) => $tld->tld === $suffix || DomainName::toAscii('x'.$tld->tld) === DomainName::toAscii('x'.$suffix));

        if ($name === '' || DomainName::problem($name.$tlds[0]->tld) !== null) {
            return $none;
        }

        $requested = $sold ? $query : null;

        return [
            'domains' => collect([$requested])->filter()->merge(array_map(fn (Tld $tld) => $name.$tld->tld, $tlds))
                ->unique()->take(self::MAX_RESULTS)->values()->all(),
            'requested' => $requested,
            'unsupported' => $suffix !== '' && ! $sold ? $suffix : null,
        ];
    }

    /** The rate limiter's key for the client signed in, else the visitor's address. */
    public static function limiterKey(): string
    {
        return 'domain-search:'.(filament()->auth()->id() ?? request()->ip());
    }

    /**
     * For the order forms (client area, public order page, admin New order): whether the domain typed is free, or null
     * when that can't be told now: not a valid name, too many lookups from this client or address ($limited, not for
     * admins), or the lookup failed.
     */
    public static function forOrder(?string $domain, bool $limited = true): ?DomainAvailability
    {
        $domain = DomainName::normalize($domain);

        if ($domain === '' || ! str_contains($domain, '.') || DomainName::problem($domain) !== null) {
            return null;
        }

        // Once a request: the form renders it more than once.
        return once(function () use ($domain, $limited) {
            $search = app(self::class);

            // A visitor's hint (public order page) asks RDAP / DNS, not the registrar: it has no captcha before it
            $registrar = ! $limited || Auth::guard('client')->check();

            try {
                return $search->known(DomainName::toAscii($domain))
                    ?? ($limited && ! RateLimiter::attempt(self::limiterKey(), self::PER_MINUTE, fn () => true) ? null : $search->check($domain, $registrar));
            } catch (Throwable) {
                return null; // the order goes ahead; it is checked again when the domain is registered
            }
        });
    }

    /** @param  bool  $registrar  false: only RDAP and DNS, never the registrar's API */
    public function check(string $domain, bool $registrar = true): DomainAvailability
    {
        $ascii = DomainName::toAscii($domain);

        if (($known = $this->known($ascii)) !== null) {
            return $known;
        }

        $found = ($registrar ? $this->fromRegistrar($domain, $ascii) : null) ?? $this->fromRdap($ascii) ?? $this->fromDns($ascii);
        Cache::put(self::cacheKey($ascii), $found->value, self::KEEP);

        return $found;
    }

    /** The answer kept from a lookup in the last 10 minutes. */
    private function known(string $ascii): ?DomainAvailability
    {
        $stored = Cache::get(self::cacheKey($ascii));

        return is_string($stored) ? DomainAvailability::tryFrom($stored) : null;
    }

    private static function cacheKey(string $ascii): string
    {
        return 'gaiacp.domain_search.'.md5($ascii);
    }

    private function fromRegistrar(string $domain, string $ascii): ?DomainAvailability
    {
        $account = Registrar::forDomain($domain);

        try {
            $registrar = $account ? $this->registrars->forRegistrar($account) : null;

            if (! $registrar instanceof ChecksAvailability || ! $registrar->configured()) {
                return null;
            }

            return $registrar->available($ascii) ? DomainAvailability::Available : DomainAvailability::Taken;
        } catch (ProvisioningException) {
            return null; // the next way answers instead
        }
    }

    private function fromRdap(string $ascii): ?DomainAvailability
    {
        $server = self::rdapServers()[substr($ascii, strrpos($ascii, '.') + 1)] ?? null;

        if ($server === null) {
            return null;
        }

        try {
            $response = Http::withMiddleware(ModuleLogger::middleware('domain_search', 'rdap'))
                ->accept('application/rdap+json')
                ->connectTimeout(3)
                ->timeout(5)
                ->get(rtrim($server, '/').'/domain/'.$ascii);
        } catch (ConnectionException) {
            return null;
        }

        return match (true) {
            $response->status() === 404 => DomainAvailability::Available,
            $response->successful() => DomainAvailability::Taken,
            default => null, // rate limited or down
        };
    }

    private function fromDns(string $ascii): DomainAvailability
    {
        $resolver = self::$nameservers ?? fn (string $ascii) => array_column(@dns_get_record($ascii, DNS_NS) ?: [], 'target');

        try {
            return $resolver($ascii) !== [] ? DomainAvailability::Taken : DomainAvailability::Unknown;
        } catch (Throwable) {
            return DomainAvailability::Unknown;
        }
    }

    /** @return array<string, string> TLD (ASCII, no dot) => RDAP base URL, from IANA's list, kept for a day (10 minutes when it can't be read) */
    public static function rdapServers(): array
    {
        $stored = Cache::get('gaiacp.rdap_servers');

        if (is_array($stored)) {
            return $stored;
        }

        $servers = [];

        try {
            $response = Http::withMiddleware(ModuleLogger::middleware('domain_search', 'rdap_servers'))->connectTimeout(3)->timeout(10)->get(self::BOOTSTRAP_URL);

            foreach ($response->successful() ? (array) $response->json('services', []) : [] as $service) {
                $url = collect($service[1] ?? [])->first(fn ($url) => is_string($url) && str_starts_with($url, 'https://'));

                foreach ($url ? (array) ($service[0] ?? []) : [] as $tld) {
                    $servers[strtolower((string) $tld)] = $url;
                }
            }
        } catch (ConnectionException) {
            // kept empty for a while, so searches fall back to DNS
        }

        Cache::put('gaiacp.rdap_servers', $servers, $servers === [] ? 600 : 86400);

        return $servers;
    }
}
