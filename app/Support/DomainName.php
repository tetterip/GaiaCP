<?php

namespace App\Support;

/**
 * Domain names as GaiaCP stores and shows them, and as registrars and panels get them.
 * An IDN (Settings → Domains → Allow IDN domains) is stored in its own letters
 * ("παράδειγμα.gr") and sent out in punycode ("xn--hxajbheg2az3al.gr").
 */
class DomainName
{
    private const IDNA = IDNA_NONTRANSITIONAL_TO_ASCII | IDNA_NONTRANSITIONAL_TO_UNICODE;

    /** Trimmed, lowercase, no trailing dot; with IDN allowed, punycode becomes the domain's own letters. */
    public static function normalize(?string $domain): string
    {
        $domain = mb_strtolower(rtrim(trim((string) $domain), '.'));

        if ($domain !== '' && self::idnAllowed() && str_contains($domain, 'xn--')) {
            $unicode = idn_to_utf8($domain, self::IDNA, INTL_IDNA_VARIANT_UTS46);
            $domain = $unicode === false ? $domain : $unicode;
        }

        return $domain;
    }

    /** The ASCII (punycode) form registrars and panels expect; unchanged when it already is ASCII. */
    public static function toAscii(string $domain): string
    {
        $domain = self::normalize($domain);

        if (! self::isIdn($domain)) {
            return $domain;
        }

        $ascii = idn_to_ascii($domain, self::IDNA, INTL_IDNA_VARIANT_UTS46);

        return $ascii === false ? $domain : $ascii;
    }

    /** Has letters outside ASCII, like "παράδειγμα.gr". */
    public static function isIdn(string $domain): bool
    {
        return preg_match('/[^\x00-\x7F]/', $domain) === 1;
    }

    /** Why the name is not a valid domain, or null when it is. */
    public static function problem(string $domain): ?string
    {
        $domain = self::normalize($domain);

        if (self::isIdn($domain) && ! self::idnAllowed()) {
            return 'Domains with non-Latin letters (IDN) are off. Allow them under Settings → Domains.';
        }

        $ascii = self::isIdn($domain) ? idn_to_ascii($domain, self::IDNA, INTL_IDNA_VARIANT_UTS46) : $domain;

        if ($ascii === false || strlen($ascii) > 253
            || ! preg_match('/^(?!-)[a-z0-9-]{1,63}(?<!-)(\.(?!-)[a-z0-9-]{1,63}(?<!-))+$/', $ascii)) {
            return 'Enter a domain name like example.gr, without http:// or www.';
        }

        return null;
    }

    /**
     * Nameservers as they are stored and sent: trimmed, lowercase, no trailing dot, no blanks or repeats.
     *
     * @param  iterable<mixed>|null  $nameservers
     * @return list<string>
     */
    public static function nameservers(?iterable $nameservers): array
    {
        return collect($nameservers ?? [])
            ->map(fn ($ns) => strtolower(rtrim(trim((string) $ns), '.')))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /** A nameserver host name such as ns1.example.com: letters, digits and hyphens, a dot between labels, a TLD in letters. */
    public static function isNameserver(string $nameserver): bool
    {
        $nameserver = strtolower(rtrim(trim($nameserver), '.'));

        return strlen($nameserver) <= 253
            && preg_match('/^((?!-)[a-z0-9-]{1,63}(?<!-)\.)+(xn--[a-z0-9-]{1,59}|[a-z]{2,63})$/', $nameserver) === 1;
    }

    public static function idnAllowed(): bool
    {
        return (bool) config('gaiacp.domains.idn', false);
    }
}
