<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Servers\ServerResource;
use App\Filament\Widgets\Concerns\FitsDashboard;
use App\Models\Server;
use App\Monitoring\LoadHistory;
use App\Monitoring\MonitorStatus;
use App\Monitoring\UptimeHistory;
use App\Plugins\PluginManager;
use App\Provisioning\ReportsLoad;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\DB;

/**
 * Each active server with a dot for its monitor's status and its uptime over 30 days beside its name, its uptime for
 * the last 24 hours and its hosting accounts against the maximum, both as a row of squares (`filament.servers.meter`),
 * a warning once its accounts reach NEARLY_FULL of the maximum, and, for a panel that reports it (ReportsLoad), its CPU
 * over the last 24 hours (`filament.servers.cpu-bar`, App\Monitoring\LoadHistory).
 */
class ServerCapacity extends Widget
{
    use FitsDashboard;

    /** The accounts bar has as many squares as the 24-hour uptime bar, so the two line up. */
    public const SQUARES = 24;

    /** The share of a server's maximum accounts from which the card warns. */
    public const NEARLY_FULL = 0.85;

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
        $servers = Server::query()->where('active', true)->withAccountsCount()->orderBy('name')->get();
        $month = self::monthRatios($servers->pluck('id')->all());

        return [
            'url' => ServerResource::getUrl(),
            'servers' => $servers
                ->map(fn (Server $server) => $this->row($server, $plugins, $month[$server->id] ?? null))
                ->all(),
        ];
    }

    /**
     * The share of the last 30 days' checks that were not down, per server, in one query (UptimeHistory reads every
     * check of one server; here only the number is needed).
     *
     * @param  list<int>  $servers
     * @return array<int, float>
     */
    public static function monthRatios(array $servers): array
    {
        return DB::table('server_monitor_checks')
            ->whereIn('server_id', $servers)
            ->where('checked_at', '>=', now()->subDays(30)->utc())
            ->where('status', '!=', MonitorStatus::Unknown->value)
            ->selectRaw('server_id, count(*) as checks, sum(case when status = ? then 1 else 0 end) as down', [MonitorStatus::Down->value])
            ->groupBy('server_id')
            ->get()
            ->mapWithKeys(fn (object $row) => [(int) $row->server_id => ((int) $row->checks - (int) $row->down) / max(1, (int) $row->checks)])
            ->all();
    }

    /** @return array<string, mixed> */
    private function row(Server $server, PluginManager $plugins, ?float $month): array
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
            // Over 30 days: the monitoring service's own number when it gives one, else the share of our checks.
            'month' => $monitored ? UptimeHistory::percent(isset($server->monitor_uptime['30d']) ? (float) $server->monitor_uptime['30d'] : $month) : null,
            'nearly_full' => ($share = $server->accountsShare()) !== null && $share >= self::NEARLY_FULL
                ? ($share >= 1 ? __('Full') : __('Nearly full'))
                : null,
            'full' => $share !== null && $share >= 1,
            'cpu' => $this->reportsLoad($server, $plugins) ? LoadHistory::for($server) : null,
        ];
    }

    /** Whether the server's panel tells its CPU load (its plugin on), so its card shows the CPU bar. */
    private function reportsLoad(Server $server, PluginManager $plugins): bool
    {
        $module = $plugins->serverModules()->get((string) $server->type);

        return $module !== null && rescue(fn () => $module->provisioner($server) instanceof ReportsLoad, false, report: false);
    }
}
