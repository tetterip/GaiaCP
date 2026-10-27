<?php

namespace Plugins\UptimeKuma;

use App\Monitoring\Monitor;
use App\Monitoring\MonitorStatus;

/**
 * Reads Uptime Kuma's Prometheus page (/metrics): one `monitor_status{monitor_id="…",monitor_name="…",…} <0-3>` line
 * per active monitor (1 up, 0 down, 2 pending, 3 maintenance; paused monitors are left out) and its
 * `monitor_response_time` in ms; Kuma 2.x adds `monitor_uptime_ratio{…,window="1d|30d|365d"}` (0-1). Kuma 1.x has no
 * monitor_id label, so its monitors are known by name.
 */
class KumaMetrics
{
    /** @return array<string, Monitor> ref => monitor, in Kuma's order */
    public static function parse(string $body): array
    {
        $status = [];
        $response = [];
        $uptime = [];

        foreach (preg_split('/\R/', $body) as $line) {
            if (! preg_match('/^(monitor_status|monitor_response_time|monitor_uptime_ratio)\{(.*)\}\s+(\S+)/', $line, $match)) {
                continue;
            }

            $labels = self::labels($match[2]);
            $ref = self::filled($labels['monitor_id'] ?? null) ?? self::filled($labels['monitor_name'] ?? null);
            if ($ref === null) {
                continue;
            }

            if ($match[1] === 'monitor_status') {
                $status[$ref] = [$labels, $match[3]];
            } elseif ($match[1] === 'monitor_uptime_ratio') {
                if (isset($labels['window']) && is_numeric($match[3])) {
                    $uptime[$ref][$labels['window']] = max(0.0, min(1.0, (float) $match[3]));
                }
            } else {
                $response[$ref] = is_numeric($match[3]) ? (int) round((float) $match[3]) : null;
            }
        }

        $monitors = [];
        foreach ($status as $ref => [$labels, $value]) {
            $monitors[(string) $ref] = new Monitor(
                ref: (string) $ref,
                name: self::filled($labels['monitor_name'] ?? null) ?? (string) $ref,
                status: match ($value) {
                    '1' => MonitorStatus::Up,
                    '0' => MonitorStatus::Down,
                    '2' => MonitorStatus::Pending,
                    '3' => MonitorStatus::Maintenance,
                    default => MonitorStatus::Unknown,
                },
                host: self::host($labels),
                responseMs: $response[$ref] ?? null,
                uptime: $uptime[$ref] ?? [],
            );
        }

        return $monitors;
    }

    /** Whether the page is Kuma's metrics at all (a login page or another app's would not have them). */
    public static function looksLikeKuma(string $body): bool
    {
        return str_contains($body, 'monitor_status');
    }

    /** @return array<string, string> */
    private static function labels(string $text): array
    {
        preg_match_all('/(\w+)="((?:[^"\\\\]|\\\\.)*)"/', $text, $pairs, PREG_SET_ORDER);

        return collect($pairs)->mapWithKeys(fn (array $pair) => [
            $pair[1] => strtr($pair[2], ['\\\\' => '\\', '\\"' => '"', '\\n' => "\n"]),
        ])->all();
    }

    /** The hostname it checks, or the host of the URL it checks. */
    private static function host(array $labels): ?string
    {
        if ($host = self::filled($labels['monitor_hostname'] ?? null)) {
            return $host;
        }

        $url = self::filled($labels['monitor_url'] ?? null);
        $host = $url ? parse_url($url, PHP_URL_HOST) : null;

        return is_string($host) && $host !== '' ? $host : null;
    }

    /** Kuma writes empty fields as "null" (or "undefined"). */
    private static function filled(?string $value): ?string
    {
        $value = trim((string) $value);

        return in_array($value, ['', 'null', 'undefined'], true) ? null : $value;
    }
}
