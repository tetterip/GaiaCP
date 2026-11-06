<?php

namespace App\Support\Health;

use App\Models\ActivityLog;
use App\Support\ModuleLogger;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Tells a monitoring service outside GaiaCP that the automation runs (gaiacp:heartbeat, every 5 minutes): Settings →
 * Automation → Ping URL gets a GET while all is well, so a stopped cron (or server) shows as a missed ping there, which
 * GaiaCP itself can't report. When a daily command's last run failed or the last backup failed, the Ping URL on failure
 * gets the GET instead (`{message}` in it becomes what failed); without one, no ping is sent, so the monitor alerts too.
 * Works with Uptime Kuma push monitors, Healthchecks.io, Better Stack heartbeats, Cronitor and the like.
 */
class Heartbeat
{
    public const LAST = 'gaiacp.health.ping';

    /** A daily command's failure counts while it is its last run and no older than this. */
    private const FAILURE_HOURS = 26;

    public function __construct(private SchedulerMonitor $monitor) {}

    public static function configured(): bool
    {
        return (bool) config('gaiacp.automation.ping_url');
    }

    /** @return list<string> what failed, in English like the log */
    public function problems(): array
    {
        $problems = [];

        foreach (SchedulerMonitor::DAILY_COMMANDS as $command) {
            $run = $this->monitor->lastRun($command);

            if ($run && $run['exit_code'] !== 0 && $run['at']->diffInHours(now(), true) < self::FAILURE_HOURS) {
                $problems[] = "{$command} failed";
            }
        }

        if (config('gaiacp.backups.enabled')
            && ActivityLog::query()->whereIn('event', ['backup.created', 'backup.failed'])->latest('id')->value('event') === 'backup.failed') {
            $problems[] = 'the last backup failed';
        }

        return $problems;
    }

    /**
     * @return array{url: ?string, problems: list<string>} the URL to call now (null: none, see the class) and why
     */
    public function plan(): array
    {
        $problems = $this->problems();
        $url = $problems === [] ? config('gaiacp.automation.ping_url') : config('gaiacp.automation.ping_fail_url');
        $message = $problems === [] ? 'OK' : 'GaiaCP: '.implode('; ', $problems);

        return ['url' => $url ? str_replace('{message}', rawurlencode($message), $url) : null, 'problems' => $problems];
    }

    /** @return array{sent: bool, problems: list<string>, status: ?int, error: ?string} */
    public function send(): array
    {
        if (! self::configured()) {
            return ['sent' => false, 'problems' => [], 'status' => null, 'error' => null];
        }

        ['url' => $url, 'problems' => $problems] = $this->plan();
        $status = $error = null;

        if ($url) {
            try {
                $status = Http::timeout(10)
                    ->withMiddleware(ModuleLogger::middleware('heartbeat', $problems ? 'ping_fail' : 'ping', [$url]))
                    ->get($url)
                    ->throw()
                    ->status();
            } catch (Throwable $e) {
                $error = $e->getMessage();
            }
        }

        $result = ['sent' => $url !== null && $error === null, 'problems' => $problems, 'status' => $status, 'error' => $error];
        Cache::forever(self::LAST, ['at' => now()->toIso8601String(), 'failure' => $problems !== []] + $result);

        return $result;
    }

    /** @return array{at: string, failure: bool, sent: bool, problems: list<string>, status: ?int, error: ?string}|null */
    public static function last(): ?array
    {
        $last = Cache::get(self::LAST);

        return is_array($last) ? $last : null;
    }
}
