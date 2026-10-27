<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Servers\ServerResource;
use App\Filament\Widgets\Concerns\FitsDashboard;
use App\Models\Server;
use App\Monitoring\UptimeHistory;
use App\Plugins\PluginManager;
use Filament\Widgets\Widget;

/** Each active server with a dot for its monitor's status, its uptime for the last 24 hours and its hosting accounts against the maximum, both as a row of squares (`filament.servers.meter`). */
class ServerCapacity extends Widget
{
    use FitsDashboard;

    /** The accounts bar has as many squares as the 24-hour uptime bar, so the two line up. */
    public const SQUARES = 24;

    protected static bool $isLazy = false;

    protected static ?int $sort = 4;

    protected string $view = 'filament.dashboard.server-capacity';

    public static function dashboardLabel(): string
    {
        return __('Servers');
    }

    public static function canView(): bool
    {
        return ServerResource::canAccess() && Server::query()->where('active', true)->exists();
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $plugins = app(PluginManager::class);

        return [
            'url' => ServerResource::getUrl(),
            'servers' => Server::query()->where('active', true)->withAccountsCount()->orderBy('name')->get()
                ->map(fn (Server $server) => $this->row($server, $plugins))
                ->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function row(Server $server, PluginManager $plugins): array
    {
        // Only while its monitoring plugin is on, so a switched-off one leaves no stale dot or bar.
        $monitored = $server->monitor_module && $plugins->monitoringModules()->has($server->monitor_module);

        return [
            'name' => $server->name,
            'panel' => $plugins->serverModule($server->type)?->label() ?? $server->type,
            'server' => $server,
            'status' => $monitored ? $server->monitor_status : null,
            // The last 24 hours, a square per hour (App\Monitoring\UptimeHistory).
            'uptime' => $monitored ? UptimeHistory::for($server, '24h') : null,
        ];
    }
}
