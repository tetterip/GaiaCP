<?php

namespace App\Support\Health;

use App\Models\ActivityLog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Remembers when cron last ran `schedule:run` (and with which PHP) and how each daily gaiacp:* command
 * last ended, so System health can tell whether the automation is running. Written by
 * App\Listeners\RecordCommandRuns; kept in the cache, so `cache:clear` forgets it until the next run.
 */
class SchedulerMonitor
{
    public const HEARTBEAT = 'gaiacp.health.scheduler';

    public const COMMAND_PREFIX = 'gaiacp.health.command.';

    /** When the heartbeat was first found missing (cache emptied, or cron never set up). */
    public const MISSING_SINCE = 'gaiacp.health.scheduler_missing_since';

    /** Set once the bell has said cron stopped, so it says it once per stop. */
    public const ALERTED = 'gaiacp.health.scheduler_alerted';

    /** Minutes without a `schedule:run` before the dashboard and the bell say cron has stopped. */
    public const STOPPED_AFTER_MINUTES = 60;

    /** The commands routes/console.php (or a bundled plugin's schedule()) runs daily. */
    public const DAILY_COMMANDS = [
        'gaiacp:send-reminders',
        'gaiacp:suspend-overdue',
        'gaiacp:terminate-scheduled',
        'gaiacp:cancel-unpaid-orders',
        'gaiacp:sync-domains',
        'gaiacp:compare-servers',
        'gaiacp:check-sites',
        'gaiacp:check-usage',
        'gaiacp:close-tickets',
        'gaiacp:lead-follow-ups',
        'gaiacp:expire-quotes',
        'gaiacp:daily-summary',
        'gaiacp:prune-logs',
        'gaiacp:check-plugin-catalogue',
        'gaiacp:check-for-update',
    ];

    public function heartbeat(): void
    {
        Cache::forever(self::HEARTBEAT, [
            'at' => now()->toIso8601String(),
            'php' => PHP_VERSION,
            'binary' => PHP_BINARY,
        ]);
        Cache::forget(self::MISSING_SINCE);

        if ($alerted = Cache::pull(self::ALERTED)) {
            ActivityLog::create([
                'event' => 'scheduler.resumed',
                'description' => 'Cron is running again',
                'properties' => ['stopped_since' => $alerted],
            ]);
        }
    }

    /**
     * When cron stopped running `schedule:run` (more than STOPPED_AFTER_MINUTES ago), or null while it runs. With no
     * heartbeat at all, the time it was first found missing counts, so an emptied cache doesn't warn at once.
     *
     * @return array{since: CarbonImmutable, seen: bool}|null
     */
    public function stopped(): ?array
    {
        if ($beat = $this->lastHeartbeat()) {
            $since = $beat['at'];
        } else {
            Cache::add(self::MISSING_SINCE, now()->toIso8601String());
            $since = CarbonImmutable::parse(Cache::get(self::MISSING_SINCE, now()->toIso8601String()));
        }

        return $since->diffInMinutes(now(), true) > self::STOPPED_AFTER_MINUTES ? ['since' => $since, 'seen' => $beat !== null] : null;
    }

    /** Rings the bell (super admins) once when cron has stopped; called on admin page loads, since cron can't. */
    public function alertIfStopped(): void
    {
        $stopped = $this->stopped();
        if ($stopped && Cache::add(self::ALERTED, $stopped['since']->toIso8601String())) {
            // No user_id: the admin whose page load noticed it gets the alert too.
            ActivityLog::create([
                'event' => 'scheduler.stopped',
                'description' => $stopped['seen'] ? 'Cron has not run since '.$stopped['since']->toDateTimeString() : 'Cron has not run yet',
                'properties' => ['last_run' => $stopped['seen'] ? $stopped['since']->toIso8601String() : null],
            ]);
        }
    }

    public function commandFinished(string $command, int $exitCode): void
    {
        Cache::forever(self::COMMAND_PREFIX.$command, [
            'at' => now()->toIso8601String(),
            'exit_code' => $exitCode,
        ]);
    }

    /** @return array{at: CarbonImmutable, php: string, binary: string}|null */
    public function lastHeartbeat(): ?array
    {
        $beat = Cache::get(self::HEARTBEAT);

        return is_array($beat) ? ['at' => CarbonImmutable::parse($beat['at']), 'php' => $beat['php'], 'binary' => $beat['binary']] : null;
    }

    /** @return array{at: CarbonImmutable, exit_code: int}|null */
    public function lastRun(string $command): ?array
    {
        $run = Cache::get(self::COMMAND_PREFIX.$command);

        return is_array($run) ? ['at' => CarbonImmutable::parse($run['at']), 'exit_code' => (int) $run['exit_code']] : null;
    }
}
