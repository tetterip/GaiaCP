<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The WHOIS lookup of the client area (Domains → WHOIS, also for visitors under Settings → Public area): who holds a
 * domain name, read from the registry's RDAP server (IANA's list, as the domain search uses), else from its WHOIS
 * server (port 43, as IANA names it) as plain text. Contact details are never shown: registries hide them anyway, and
 * only the registrar, the dates, the statuses and the nameservers are read from RDAP.
 */
class Whois
{
    /** IANA's WHOIS server, which names each TLD's own. */
    public const IANA = 'whois.iana.org';

    /** Lookups a client (or a visitor's address) may make in a minute. */
    public const PER_MINUTE = 10;

    /** The most of a WHOIS server's answer that is kept, in bytes. */
    private const MAX_TEXT = 20000;

    private static ?Closure $query = null;

    /** Tests: what a WHOIS server answers (fn (string $server, string $query): ?string), in place of port 43. */
    public static function queryUsing(?Closure $query): void
    {
        self::$query = $query;
    }

    /** The domain name typed (also as a web address, with www.), in lower case, or null when it is not one. */
    public static function domain(string $typed): ?string
    {
        $typed = preg_replace('#^(https?://)?(www\.)?#i', '', trim($typed));
        $domain = DomainName::normalize(strtok($typed, '/?#:') ?: '');

        return str_contains($domain, '.') && DomainName::problem($domain) === null ? $domain : null;
    }

    /**
     * What is known of a domain: registered or not, its registrar, dates (Y-m-d), statuses, nameservers and DNSSEC from
     * RDAP; or the WHOIS server's text ($text) when the TLD has no RDAP. Null when neither could answer.
     *
     * @return array{domain: string, registered: bool, registrar: ?string, created: ?string, expires: ?string, updated: ?string, statuses: list<string>, nameservers: list<string>, dnssec: ?bool, text: ?string}|null
     */
    public function lookup(string $domain): ?array
    {
        $ascii = DomainName::toAscii($domain);

        return $this->fromRdap($domain, $ascii) ?? $this->fromWhois($domain, $ascii);
    }

    private function fromRdap(string $domain, string $ascii): ?array
    {
        $server = DomainSearch::rdapServers()[substr($ascii, strrpos($ascii, '.') + 1)] ?? null;

        if ($server === null) {
            return null;
        }

        try {
            $response = Http::withMiddleware(ModuleLogger::middleware('whois', 'rdap'))
                ->accept('application/rdap+json')
                ->connectTimeout(3)
                ->timeout(8)
                ->get(rtrim($server, '/').'/domain/'.$ascii);
        } catch (ConnectionException) {
            return null;
        }

        if ($response->status() === 404) {
            return self::result($domain, registered: false);
        }

        if (! $response->successful() || ! is_array($data = $response->json())) {
            return null;
        }

        $events = collect((array) ($data['events'] ?? []))
            ->mapWithKeys(fn ($event) => [(string) ($event['eventAction'] ?? '') => self::date($event['eventDate'] ?? null)]);
        $registrar = collect((array) ($data['entities'] ?? []))
            ->first(fn ($entity) => in_array('registrar', (array) ($entity['roles'] ?? []), true));

        return self::result($domain, registered: true, details: [
            'registrar' => $registrar ? self::vcardName($registrar) : null,
            'created' => $events['registration'] ?? null,
            'expires' => $events['expiration'] ?? null,
            'updated' => $events['last changed'] ?? null,
            'statuses' => collect((array) ($data['status'] ?? []))->filter(fn ($status) => is_string($status))->map(fn (string $status) => ucfirst($status))->values()->all(),
            'nameservers' => collect((array) ($data['nameservers'] ?? []))
                ->map(fn ($nameserver) => strtolower(rtrim((string) ($nameserver['ldhName'] ?? ''), '.')))
                ->filter()->unique()->values()->all(),
            'dnssec' => isset($data['secureDNS']['delegationSigned']) ? (bool) $data['secureDNS']['delegationSigned'] : null,
        ]);
    }

    private function fromWhois(string $domain, string $ascii): ?array
    {
        $tld = substr($ascii, strrpos($ascii, '.') + 1);
        $server = Cache::remember('gaiacp.whois_server.'.$tld, 86400, function () use ($tld) {
            preg_match('/^whois:\s*(\S+)/mi', (string) $this->ask(self::IANA, $tld), $match);

            return $match[1] ?? '';
        });

        $text = $server !== '' ? $this->ask($server, $ascii) : null;

        if (blank($text)) {
            return null;
        }

        return self::result($domain, registered: ! preg_match('/^\s*(no match|not found|no data found|no entries found|status:\s*free|domain not found)/mi', $text), details: [
            'text' => mb_substr(trim($text), 0, self::MAX_TEXT),
        ]);
    }

    /** A WHOIS server's answer to a query (port 43), or null when it can't be reached. */
    private function ask(string $server, string $query): ?string
    {
        if (self::$query) {
            return (self::$query)($server, $query);
        }

        try {
            $socket = @fsockopen($server, 43, $code, $error, 5);

            if (! $socket) {
                return null;
            }

            stream_set_timeout($socket, 5);
            fwrite($socket, $query."\r\n");
            $text = '';

            while (! feof($socket) && strlen($text) < self::MAX_TEXT * 2) {
                $text .= (string) fgets($socket, 1024);
            }

            fclose($socket);

            return mb_convert_encoding($text, 'UTF-8', 'UTF-8, ISO-8859-1');
        } catch (Throwable) {
            return null;
        }
    }

    private static function result(string $domain, bool $registered, array $details = []): array
    {
        return [
            'domain' => $domain,
            'registered' => $registered,
            'registrar' => null,
            'created' => null,
            'expires' => null,
            'updated' => null,
            'statuses' => [],
            'nameservers' => [],
            'dnssec' => null,
            'text' => null,
            ...$details,
        ];
    }

    private static function date(mixed $value): ?string
    {
        try {
            return is_string($value) && $value !== '' ? CarbonImmutable::parse($value)->toDateString() : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** The name (fn) in an RDAP entity's vCard, else its handle. */
    private static function vcardName(array $entity): ?string
    {
        foreach ((array) ($entity['vcardArray'][1] ?? []) as $field) {
            if (($field[0] ?? null) === 'fn' && filled($field[3] ?? null)) {
                return (string) $field[3];
            }
        }

        return filled($entity['handle'] ?? null) ? (string) $entity['handle'] : null;
    }
}
