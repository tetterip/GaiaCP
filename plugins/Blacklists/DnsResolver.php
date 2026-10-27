<?php

namespace Plugins\Blacklists;

/** The DNS lookups of the check, behind a class so tests answer them instead of the network. */
class DnsResolver
{
    /** @return list<string> the A records of $host; empty when there is none (NXDOMAIN = not listed) */
    public function a(string $host): array
    {
        $records = @dns_get_record($host, DNS_A);

        return is_array($records) ? array_values(array_filter(array_column($records, 'ip'))) : [];
    }

    /** @return list<string> the IPv4 and IPv6 addresses of a server's hostname */
    public function addresses(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);

        return is_array($records)
            ? array_values(array_unique(array_filter(array_map(fn (array $r) => $r['ip'] ?? $r['ipv6'] ?? null, $records))))
            : [];
    }
}
