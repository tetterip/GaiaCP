<?php

namespace Plugins\BetterStack;

use App\Monitoring\Monitor;
use App\Monitoring\MonitorStatus;

/**
 * Reads Better Stack Uptime's monitors (API v2, GET /api/v2/monitors, JSON:API): each with an `id` and `attributes`
 * holding `pronounceable_name`, `url` (a URL, or a hostname / IP for ping, TCP and UDP monitors) and `status` (up, down,
 * validating, pending, maintenance, paused). The list has no uptime percentage; GaiaCP keeps its own history.
 */
class BetterStackMonitors
{
    /** @return array<string, Monitor> ref => monitor, in Better Stack's order */
    public static function parse(array $monitors): array
    {
        $parsed = [];

        foreach ($monitors as $values) {
            if (! is_array($values) || ! isset($values['id'])) {
                continue;
            }

            $ref = (string) $values['id'];
            $attributes = is_array($values['attributes'] ?? null) ? $values['attributes'] : [];
            $parsed[$ref] = new Monitor(
                ref: $ref,
                name: trim((string) ($attributes['pronounceable_name'] ?? $attributes['name'] ?? '')) ?: (string) ($attributes['url'] ?? $ref),
                status: self::status($attributes),
                host: self::host((string) ($attributes['url'] ?? '')),
                responseMs: null,
                uptime: [],
            );
        }

        return $parsed;
    }

    private static function status(array $attributes): MonitorStatus
    {
        if (($attributes['paused'] ?? false) === true) {
            return MonitorStatus::Unknown;
        }

        return match (strtolower((string) ($attributes['status'] ?? ''))) {
            'up' => MonitorStatus::Up,
            'down' => MonitorStatus::Down,
            'maintenance' => MonitorStatus::Maintenance,
            'pending', 'validating' => MonitorStatus::Pending,
            default => MonitorStatus::Unknown,
        };
    }

    private static function host(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }

        $host = str_contains($url, '://') ? parse_url($url, PHP_URL_HOST) : explode(':', $url)[0];

        return is_string($host) && $host !== '' ? $host : null;
    }
}
