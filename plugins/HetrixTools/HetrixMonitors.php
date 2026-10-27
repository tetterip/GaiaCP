<?php

namespace Plugins\HetrixTools;

use App\Monitoring\Monitor;
use App\Monitoring\MonitorStatus;

/**
 * Reads HetrixTools' list of uptime monitors (API v3, GET /v3/uptime-monitors): each with an `id`, a `name`, a `target`
 * (a URL for website monitors, a hostname or IP for ping / service ones), its state (`uptime_status`: up / down /
 * maintenance; `monitor_status` paused when it is not checked) and its `uptime` percentage. Field names are read loosely
 * (status / state, uptime as a number or per period) so a small change on their side doesn't blank every server.
 */
class HetrixMonitors
{
    /** @return array<string, Monitor> ref => monitor, in HetrixTools' order */
    public static function parse(array $monitors): array
    {
        $parsed = [];

        foreach ($monitors as $values) {
            if (! is_array($values) || ! isset($values['id'])) {
                continue;
            }

            $ref = (string) $values['id'];
            $parsed[$ref] = new Monitor(
                ref: $ref,
                name: trim((string) ($values['name'] ?? '')) ?: $ref,
                status: self::status($values),
                host: self::host((string) ($values['target'] ?? $values['resolve_address'] ?? '')),
                responseMs: self::responseMs($values),
                uptime: self::uptime($values['uptime'] ?? null),
            );
        }

        return $parsed;
    }

    private static function status(array $values): MonitorStatus
    {
        $monitor = strtolower((string) ($values['monitor_status'] ?? $values['status'] ?? ''));
        if (in_array($monitor, ['paused', 'inactive', 'disabled'], true)) {
            return MonitorStatus::Unknown;
        }
        if ($monitor === 'maintenance') {
            return MonitorStatus::Maintenance;
        }

        return match (strtolower((string) ($values['uptime_status'] ?? $values['state'] ?? $values['status'] ?? ''))) {
            'up', 'online', 'ok', '1' => MonitorStatus::Up,
            'down', 'offline', '0' => MonitorStatus::Down,
            'maintenance' => MonitorStatus::Maintenance,
            'pending', 'unknown', 'checking', 'new' => MonitorStatus::Pending,
            default => MonitorStatus::Unknown,
        };
    }

    private static function host(string $target): ?string
    {
        $target = trim($target);
        if ($target === '') {
            return null;
        }

        $host = str_contains($target, '://') ? parse_url($target, PHP_URL_HOST) : explode(':', $target)[0];

        return is_string($host) && $host !== '' ? $host : null;
    }

    /** The response time it reports, else the average of its locations' last ones. */
    private static function responseMs(array $values): ?int
    {
        if (is_numeric($values['response_time'] ?? null)) {
            return (int) round((float) $values['response_time']);
        }

        $times = collect(is_array($values['locations'] ?? null) ? $values['locations'] : [])
            ->map(fn ($location) => is_array($location) ? ($location['response_time'] ?? null) : null)
            ->filter(fn ($time) => is_numeric($time) && $time > 0);

        return $times->isEmpty() ? null : (int) round($times->avg());
    }

    /** "99.98" (the monitor's uptime, 30 days on HetrixTools) or {"1d": …, "30d": …} @return array<string, float> */
    private static function uptime(mixed $uptime): array
    {
        $ratio = fn ($percent) => max(0.0, min(1.0, (float) $percent / 100));

        if (is_numeric($uptime)) {
            return ['30d' => $ratio($uptime)];
        }

        if (! is_array($uptime)) {
            return [];
        }

        $windows = ['1d' => ['1d', '24h', 'day', 'last_24h'], '30d' => ['30d', 'month', 'last_30d'], '365d' => ['365d', '1y', 'year', 'last_365d']];

        return collect($windows)->map(fn (array $keys) => collect($keys)->map(fn ($key) => $uptime[$key] ?? null)->first(fn ($v) => is_numeric($v)))
            ->filter(fn ($percent) => $percent !== null)
            ->map($ratio)
            ->all();
    }
}
