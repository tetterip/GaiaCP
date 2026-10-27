<?php

namespace App\Support;

use App\Enums\ServiceType;
use App\Models\Client;
use App\Models\Server;
use App\Models\Service;
use App\Monitoring\MonitorStatus;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * The client area's notice about a server problem (Settings → Client area → Server problems): a client's visible,
 * active or suspended hosting on a server whose monitor says down or maintenance (servers.monitor_status, kept by
 * App\Monitoring\ServerMonitoring). Nothing is stored: the notice goes away once the monitor says up again.
 */
final class ServerIncidents
{
    public const STATUSES = [MonitorStatus::Down, MonitorStatus::Maintenance];

    public static function enabled(): bool
    {
        return (bool) config('gaiacp.client_area.incident_notice', true);
    }

    /** @return Collection<int, Service> with their server, the down ones first */
    public static function forClient(?Client $client): Collection
    {
        if (! $client || ! self::enabled()) {
            return collect();
        }

        return $client->services()
            ->clientVisible()
            ->billable()
            ->where('type', ServiceType::Hosting)
            ->whereHas('server', fn ($query) => $query->where('active', true)
                ->whereIn('monitor_status', array_map(fn (MonitorStatus $s) => $s->value, self::STATUSES)))
            ->with('server')
            ->orderBy('domain')
            ->get()
            ->sortBy(fn (Service $s) => $s->server->monitor_status === MonitorStatus::Down ? 0 : 1)
            ->values();
    }

    /**
     * What the monitors say now, for the AI assistant's notice text: each active server down or in maintenance by its
     * public name (none when it has none: never its hostname or address), since when and how many hosting accounts
     * are on it. No client is named.
     *
     * @return list<array{server: string, status: string, since: string, accounts: int}>
     */
    public static function now(): array
    {
        return Server::query()
            ->where('active', true)
            ->whereIn('monitor_status', array_map(fn (MonitorStatus $s) => $s->value, self::STATUSES))
            ->withCount(['services as accounts' => fn ($query) => $query->billable()->where('type', ServiceType::Hosting)])
            ->get()
            ->map(fn (Server $server) => [
                'server' => filled($server->public_name) ? (string) $server->public_name : 'a server (its name is not given)',
                'status' => $server->monitor_status === MonitorStatus::Maintenance ? 'planned maintenance' : 'down',
                'since' => $server->monitor_status_since
                    ? $server->monitor_status_since->format('Y-m-d H:i').' ('.$server->monitor_status_since->locale('en')->diffForHumans(syntax: CarbonInterface::DIFF_ABSOLUTE).')'
                    : 'a moment ago',
                'accounts' => (int) $server->accounts,
            ])
            ->values()
            ->all();
    }

    public static function defaultMessage(): string
    {
        return __('We know about it and are working to fix it. You don\'t need to contact us; this notice goes away by itself once everything works again.');
    }

    public static function message(): string
    {
        return filled($message = config('gaiacp.client_area.incident_message')) ? (string) $message : self::defaultMessage();
    }
}
