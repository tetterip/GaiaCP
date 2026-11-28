<?php

namespace App\Monitoring;

use App\Models\ActivityLog;
use App\Models\Server;
use App\Plugins\PluginManager;
use App\Provisioning\ProvisioningException;
use App\Provisioning\ReportsLoad;
use App\Support\ModuleLogger;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Reads how busy each active server's processors are from its panel (ReportsLoad), every 5 minutes
 * (gaiacp:check-load), for the CPU bar on the dashboard's Servers card (LoadHistory). A server that stays at or above
 * Settings → Automation → CPU warning for BUSY_READINGS readings in a row logs `server.cpu_high` (the bell, super
 * admins) once; going back under it logs `server.cpu_normal`. A panel that can't be read is skipped: no reading, its
 * bar shows "—". The calls stay out of the module log unless they fail. Safe to re-run.
 */
class ServerLoadCheck
{
    /** Readings in a row at or over the warning: 3 at 5 minutes apart = 15 minutes. */
    public const BUSY_READINGS = 3;

    /** Readings are kept this long (gaiacp:prune-logs), enough for the 24-hour bar. */
    public const KEEP_DAYS = 2;

    public function __construct(private PluginManager $plugins) {}

    /** @return array{servers: int, read: int, failed: list<string>} */
    public function check(): array
    {
        $result = ['servers' => 0, 'read' => 0, 'failed' => []];
        $modules = $this->plugins->serverModules();
        $warning = config('gaiacp.automation.cpu_warning_percent');

        foreach (Server::query()->where('active', true)->orderBy('name')->get() as $server) {
            $module = $modules->get((string) $server->type);
            if (! $module) {
                continue; // its plugin is off
            }

            try {
                $panel = $module->provisioner($server);
                if (! $panel instanceof ReportsLoad) {
                    continue;
                }

                $result['servers']++;
                $load = ModuleLogger::quietly(fn () => $panel->load());
            } catch (ProvisioningException $e) {
                $result['failed'][] = "{$server->name}: {$e->getMessage()}";

                continue;
            } catch (Throwable $e) {
                report($e);
                $result['failed'][] = "{$server->name}: {$e->getMessage()}";

                continue;
            }

            // The panel's own count wins over the one typed on the server form.
            if ($load->cores && $load->cores !== $server->cpu_cores) {
                $server->forceFill(['cpu_cores' => $load->cores])->saveQuietly();
            }

            $percent = $load->percent($server->cpu_cores);
            DB::table('server_load_readings')->insert([
                'server_id' => $server->id,
                'percent' => $percent,
                'load' => $load->load === null ? null : round($load->load, 2),
                'checked_at' => now()->utc(),
            ]);
            $result['read']++;

            $this->warn($server, $percent, $warning === null || $warning === '' ? null : (int) $warning);
        }

        return $result;
    }

    /** Rings the bell once when the CPU has stayed busy, and notes when it calms down. */
    private function warn(Server $server, ?float $percent, ?int $warning): void
    {
        if ($warning === null) {
            if ($server->cpu_alerted_at) {
                $server->forceFill(['cpu_alerted_at' => null])->saveQuietly();
            }

            return;
        }

        if ($server->cpu_alerted_at) {
            if ($percent !== null && $percent < $warning) {
                $server->forceFill(['cpu_alerted_at' => null])->saveQuietly();
                ActivityLog::record('server.cpu_normal', "Server {$server->name}: CPU back to {$percent}%", properties: ['server_id' => $server->id, 'percent' => $percent]);
            }

            return;
        }

        $recent = DB::table('server_load_readings')
            ->where('server_id', $server->id)
            ->where('checked_at', '>=', now()->subMinutes(self::BUSY_READINGS * 5 + 2)->utc())
            ->orderByDesc('checked_at')
            ->limit(self::BUSY_READINGS)
            ->pluck('percent');

        if ($recent->count() < self::BUSY_READINGS || $recent->contains(fn ($value) => $value === null || (float) $value < $warning)) {
            return;
        }

        $server->forceFill(['cpu_alerted_at' => now()])->saveQuietly();
        $minutes = self::BUSY_READINGS * 5;
        ActivityLog::record('server.cpu_high', "Server {$server->name}: CPU at {$percent}% for {$minutes} minutes or more", properties: [
            'server_id' => $server->id,
            'percent' => $percent,
            'minutes' => $minutes,
        ]);
    }
}
