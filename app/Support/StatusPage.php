<?php

namespace App\Support;

use App\Models\Server;
use App\Monitoring\MonitorStatus;
use App\Monitoring\UptimeHistory;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The servers' status page (Settings → Public area → Status page, Client\Pages\ServerStatus): every active server that
 * a monitoring service checks and that is not left off it (servers.on_status_page), under its public name, else its
 * name. Never its hostname or IP address. Its state is the monitor's (servers.monitor_status), with the uptime squares
 * of the last 30 days and 24 hours (UptimeHistory).
 */
final class StatusPage
{
    public static function enabled(): bool
    {
        return (bool) config('gaiacp.public_area.status');
    }

    /** @return Collection<int, array{name: string, status: MonitorStatus, since: ?CarbonImmutable, uptime: array{'30d': array<string, mixed>, '24h': array<string, mixed>}}> */
    public static function servers(): Collection
    {
        return Server::query()
            ->where('active', true)
            ->where('on_status_page', true)
            ->whereNotNull('monitor_module')
            ->get()
            ->map(fn (Server $server) => [
                'name' => self::name($server),
                'status' => $server->monitor_status ?? MonitorStatus::Unknown,
                'since' => $server->monitor_status_since,
                'uptime' => ['30d' => UptimeHistory::for($server, '30d'), '24h' => UptimeHistory::for($server, '24h')],
            ])
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();
    }

    /** What the status page calls a server: its public name, else its name. */
    public static function name(Server $server): string
    {
        return filled($server->public_name) ? (string) $server->public_name : (string) $server->name;
    }

    /** @param  Collection<int, array{status: MonitorStatus}>  $servers  the down ones, then those in maintenance */
    public static function problems(Collection $servers): Collection
    {
        return $servers->filter(fn (array $server) => in_array($server['status'], ServerIncidents::STATUSES, true))
            ->sortBy(fn (array $server) => $server['status'] === MonitorStatus::Down ? 0 : 1)
            ->values();
    }
}
