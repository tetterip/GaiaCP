<?php

namespace Plugins\SiteChecks;

use Carbon\CarbonImmutable;

/** The DNS lookups and HTTPS connections of the check, behind a class so tests answer them instead of the network. */
class SiteProbe
{
    public const TIMEOUT = 8;

    /** @return list<string> the IPv4 and IPv6 addresses $host points to */
    public function addresses(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);

        return is_array($records)
            ? array_values(array_unique(array_filter(array_map(fn (array $r) => $r['ip'] ?? $r['ipv6'] ?? null, $records))))
            : [];
    }

    /** @return list<string> the nameservers of $host, or of the nearest parent that has some (www.example.com → example.com) */
    public function nameservers(string $host): array
    {
        $labels = explode('.', $host);

        while (count($labels) >= 2) {
            $records = @dns_get_record(implode('.', $labels), DNS_NS);
            $targets = is_array($records) ? array_values(array_filter(array_column($records, 'target'))) : [];

            if ($targets !== []) {
                return array_map('strtolower', $targets);
            }

            array_shift($labels);
        }

        return [];
    }

    /**
     * The certificate $host answers with on port 443: whether the system's CAs trust it for that name, until when, and
     * from whom. When nothing answers HTTPS, no valid_to and the reason in error.
     *
     * @return array{trusted: bool, valid_to: ?CarbonImmutable, issuer: ?string, names: list<string>, error: ?string}
     */
    public function certificate(string $host): array
    {
        $error = null;
        $certificate = $this->connect($host, true, $error);
        $trusted = $certificate !== null;

        // Not trusted: read it without checking, to say why
        $certificate ??= $this->connect($host, false, $error);

        if ($certificate === null) {
            return ['trusted' => false, 'valid_to' => null, 'issuer' => null, 'names' => [], 'error' => $error ?? 'No answer on port 443'];
        }

        $parsed = openssl_x509_parse($certificate) ?: [];
        $names = [];
        foreach (explode(',', (string) ($parsed['extensions']['subjectAltName'] ?? '')) as $name) {
            if (str_starts_with($name = trim($name), 'DNS:')) {
                $names[] = strtolower(substr($name, 4));
            }
        }

        return [
            'trusted' => $trusted,
            'valid_to' => isset($parsed['validTo_time_t']) ? CarbonImmutable::createFromTimestamp($parsed['validTo_time_t']) : null,
            'issuer' => $parsed['issuer']['O'] ?? $parsed['issuer']['CN'] ?? null,
            'names' => $names ?: array_filter([strtolower((string) ($parsed['subject']['CN'] ?? ''))]),
            'error' => $trusted ? null : $error,
        ];
    }

    /** @return \OpenSSLCertificate|null */
    private function connect(string $host, bool $verify, ?string &$error): mixed
    {
        $context = stream_context_create(['ssl' => [
            'capture_peer_cert' => true,
            'verify_peer' => $verify,
            'verify_peer_name' => $verify,
            'peer_name' => $host,
            'SNI_enabled' => true,
        ]]);

        $errno = 0;
        $errstr = '';
        $warnings = [];
        // OpenSSL says why (e.g. "certificate verify failed") only in warnings
        set_error_handler(function (int $level, string $message) use (&$warnings) {
            $warnings[] = preg_replace(['/^stream_socket_client\(\): /', '/\s+/'], ['', ' '], $message);

            return true;
        });

        try {
            $stream = stream_socket_client("ssl://{$host}:443", $errno, $errstr, self::TIMEOUT, STREAM_CLIENT_CONNECT, $context);
        } finally {
            restore_error_handler();
        }

        if ($stream === false) {
            $error = collect($warnings)->first(fn (string $w) => str_contains($w, 'OpenSSL Error') || str_contains($w, 'certificate'))
                ?? (trim($errstr) !== '' ? trim($errstr) : ($warnings[0] ?? null));

            return null;
        }

        $certificate = stream_context_get_params($stream)['options']['ssl']['peer_certificate'] ?? null;
        fclose($stream);

        return $certificate;
    }
}
