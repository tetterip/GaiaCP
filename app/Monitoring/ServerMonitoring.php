<?php

namespace App\Monitoring;

use App\Models\ActivityLog;
use App\Models\Server;
use App\Plugins\PluginManager;
use Carbon\CarbonInterval;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Copies each server's status from the monitor it is linked to (servers.monitor_module / monitor_ref), a module at a
 * time, one call each. A server going down logs `server.down`, coming back from down `server.up` (both on the bell,
 * super admins). A service that can't be read logs `monitoring.failed` once until it answers again, and its servers
 * keep their last status. Run by gaiacp:check-monitors every few minutes and Servers → Check now; safe to re-run.
 */
class ServerMonitoring
{
    /** How long the monitors read for the server form's picker are kept. */
    public const PICKER_SECONDS = 60;

    private const FAILING = 'gaiacp.monitoring.failing.';

    public function __construct(private PluginManager $plugins) {}

    /** @return array{servers: int, changed: int, failed: list<string>} */
    public function check(): array
    {
        $result = ['servers' => 0, 'changed' => 0, 'failed' => []];
        $linked = Server::query()->whereNotNull('monitor_module')->whereNotNull('monitor_ref')->get()->groupBy('monitor_module');

        foreach ($linked as $key => $servers) {
            $module = $this->plugins->monitoringModules()->get($key);
            if (! $module || ! $module->monitoringConfigured()) {
                continue; // plugin off or not set up: statuses stay as they were
            }

            try {
                $monitors = $module->monitors();
            } catch (MonitoringException $e) {
                $this->failed($module, $e);
                $result['failed'][] = $module->monitoringLabel().': '.$e->getMessage();

                continue;
            }

            $this->answered($module);
            $this->remember($module, $monitors);

            $checks = [];
            foreach ($servers as $server) {
                $monitor = $monitors[$server->monitor_ref] ?? null;
                $result['servers']++;
                $result['changed'] += (int) $this->apply($server, $monitor, $module);
                $checks[] = ['server_id' => $server->id, 'status' => ($monitor?->status ?? MonitorStatus::Unknown)->value, 'checked_at' => now()];

                if (($monitor?->uptime ?: null) !== $server->monitor_uptime) {
                    $server->forceFill(['monitor_uptime' => $monitor?->uptime ?: null])->saveQuietly();
                }
            }

            // The uptime bar's history (UptimeHistory); gaiacp:prune-logs drops it after UptimeHistory::KEEP_DAYS.
            DB::table('server_monitor_checks')->insert($checks);
            Server::query()->whereKey($servers->modelKeys())->update(['monitor_checked_at' => now()]);
        }

        return $result;
    }

    /** Stores the monitor's status on the server; true when it changed. */
    private function apply(Server $server, ?Monitor $monitor, MonitoringModule $module): bool
    {
        $status = $monitor?->status ?? MonitorStatus::Unknown;
        $previous = $server->monitor_status;

        if ($previous === $status) {
            return false;
        }

        $since = $server->monitor_status_since;
        $server->forceFill(['monitor_status' => $status, 'monitor_status_since' => now()])->save();
        $properties = ['server_id' => $server->id, 'module' => $module->monitoringKey(), 'monitor' => $monitor?->name ?? $server->monitor_ref];

        if ($status === MonitorStatus::Down) {
            ActivityLog::record('server.down', "Server {$server->name} is down ({$module->monitoringLabel()})", properties: $properties);
        } elseif ($status === MonitorStatus::Up && $previous === MonitorStatus::Down) {
            $for = $since ? CarbonInterval::seconds((int) $since->diffInSeconds(now()))->cascade()->locale('en')->forHumans(['short' => true, 'parts' => 2]) : null;
            ActivityLog::record('server.up', "Server {$server->name} is up again".($for ? " after {$for}" : '')." ({$module->monitoringLabel()})", properties: $properties + ['down_seconds' => $since ? (int) $since->diffInSeconds(now()) : null]);
        }

        return true;
    }

    private function failed(MonitoringModule $module, MonitoringException $e): void
    {
        if (Cache::get(self::FAILING.$module->monitoringKey())) {
            return;
        }

        Cache::forever(self::FAILING.$module->monitoringKey(), true);
        ActivityLog::record('monitoring.failed', "{$module->monitoringLabel()} could not be read: {$e->getMessage()}", properties: ['module' => $module->monitoringKey()]);
    }

    private function answered(MonitoringModule $module): void
    {
        if (Cache::pull(self::FAILING.$module->monitoringKey())) {
            ActivityLog::record('monitoring.recovered', "{$module->monitoringLabel()} answers again", properties: ['module' => $module->monitoringKey()]);
        }
    }

    /**
     * The module's monitors for the server form, kept for a minute.
     *
     * @return array<string, Monitor>
     *
     * @throws MonitoringException
     */
    public function monitors(MonitoringModule $module): array
    {
        $cached = Cache::get($this->pickerKey($module));
        // (an entry written by an older version holds objects: read again)
        if (is_array($cached) && array_filter($cached, 'is_array') === $cached) {
            return array_map(fn (array $values) => Monitor::fromArray($values), $cached);
        }

        return $this->remember($module, $module->monitors());
    }

    /**
     * Kept as plain arrays: the cache unserializes no objects (cache.serializable_classes).
     *
     * @param  array<string, Monitor>  $monitors
     * @return array<string, Monitor>
     */
    private function remember(MonitoringModule $module, array $monitors): array
    {
        Cache::put($this->pickerKey($module), array_map(fn (Monitor $monitor) => $monitor->toArray(), $monitors), self::PICKER_SECONDS);

        return $monitors;
    }

    /** The monitor that checks this hostname or IP address, to pre-select it for a server. */
    public function suggest(MonitoringModule $module, ?string ...$hosts): ?string
    {
        $hosts = array_filter(array_map(fn (?string $host) => $host ? strtolower(trim($host)) : null, $hosts));

        try {
            $monitors = $this->monitors($module);
        } catch (MonitoringException) {
            return null;
        }

        foreach ($monitors as $monitor) {
            if ($monitor->host !== null && in_array(strtolower($monitor->host), $hosts, true)) {
                return $monitor->ref;
            }
        }

        return null;
    }

    public function forget(MonitoringModule $module): void
    {
        Cache::forget($this->pickerKey($module));
    }

    private function pickerKey(MonitoringModule $module): string
    {
        return 'gaiacp.monitoring.monitors.'.$module->monitoringKey();
    }
}
