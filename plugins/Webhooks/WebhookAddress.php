<?php

namespace Plugins\Webhooks;

use Closure;

/**
 * Keeps webhooks off GaiaCP's own network: a URL whose host is (or resolves to) a loopback, private, link-local
 * (169.254.169.254, cloud metadata) or reserved address is refused when saved and again when sent, unless
 * gaiacp.webhooks.allow_private_addresses is on (GAIACP_WEBHOOKS_ALLOW_PRIVATE=true, e.g. for n8n on the same server).
 * WebhookSender connects to the address checked here, so a DNS answer that changes in between is not followed.
 */
class WebhookAddress
{
    /** @var (Closure(string): list<string>)|null for tests: host => its addresses */
    public static ?Closure $resolver = null;

    /**
     * @return array{error: string|null, ips: list<string>} why the URL may not be used (null = it may), and the
     *                                                      addresses its host resolved to
     */
    public static function inspect(string $url): array
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return ['error' => 'The URL has no host', 'ips' => []];
        }

        $host = trim($host, '[]');
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : self::resolve($host);

        if ((bool) config('gaiacp.webhooks.allow_private_addresses', false)) {
            return ['error' => null, 'ips' => $ips];
        }

        if ($ips === []) {
            return ['error' => "The host {$host} could not be found", 'ips' => []];
        }

        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE)) {
                return ['error' => "{$host} is a local or private network address ({$ip})", 'ips' => $ips];
            }
        }

        return ['error' => null, 'ips' => $ips];
    }

    /** @return list<string> */
    private static function resolve(string $host): array
    {
        if (self::$resolver !== null) {
            return array_values((self::$resolver)($host));
        }

        $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];
        $ips = array_merge(
            array_column(array_filter($records, fn ($r) => isset($r['ip'])), 'ip'),
            array_column(array_filter($records, fn ($r) => isset($r['ipv6'])), 'ipv6'),
        );

        return array_values(array_unique($ips ?: (gethostbynamel($host) ?: [])));
    }
}
