<?php

namespace Plugins\UptimeRobot;

use App\Monitoring\Monitor;
use App\Monitoring\MonitorStatus;

/**
 * Reads UptimeRobot's getMonitors answer (API v2): `monitors` with `id`, `friendly_name`, `url` (a URL for HTTP /
 * keyword monitors, a hostname or IP for ping / port ones), `status` (0 paused, 1 not checked yet, 2 up, 8 seems down,
 * 9 down), `custom_uptime_ratio` ("99.995-99.900-100.000", percentages for the periods asked) and `response_times`.
 */
class RobotMonitors
{
    /** The periods asked for (custom_uptime_ratios, in days), in order, and the Monitor::$uptime window of each. */
    public const WINDOWS = ['1' => '1d', '30' => '30d', '365' => '365d'];

    /** @return array<string, Monitor> ref => monitor, in UptimeRobot's order */
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
                name: trim((string) ($values['friendly_name'] ?? '')) ?: $ref,
                status: match ((int) ($values['status'] ?? -1)) {
                    2 => MonitorStatus::Up,
                    9 => MonitorStatus::Down,
                    1, 8 => MonitorStatus::Pending, // not checked yet, or one failed check not yet confirmed
                    default => MonitorStatus::Unknown, // paused
                },
                host: self::host((string) ($values['url'] ?? '')),
                responseMs: self::responseMs($values),
                uptime: self::uptime((string) ($values['custom_uptime_ratio'] ?? '')),
            );
        }

        return $parsed;
    }

    private static function host(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }

        $host = str_contains($url, '://') ? parse_url($url, PHP_URL_HOST) : $url;

        return is_string($host) && $host !== '' ? $host : null;
    }

    private static function responseMs(array $values): ?int
    {
        $latest = $values['response_times'][0]['value'] ?? $values['average_response_time'] ?? null;

        return is_numeric($latest) ? (int) round((float) $latest) : null;
    }

    /** @return array<string, float> */
    private static function uptime(string $ratios): array
    {
        $uptime = [];
        foreach (array_values(self::WINDOWS) as $i => $window) {
            $percent = explode('-', $ratios)[$i] ?? null;
            if (is_numeric($percent)) {
                $uptime[$window] = max(0.0, min(1.0, (float) $percent / 100));
            }
        }

        return $uptime;
    }
}
