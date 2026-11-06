<?php

namespace Tests\Feature;

use App\Plugins\PluginManager;
use Cron\CronExpression;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ScheduleTest extends TestCase
{
    /**
     * A run that dies keeps its withoutOverlapping() lock until the lock's minutes pass (Laravel's default, 24 hours,
     * stopped an every-minute command for a day): an hourly or daily one's lock ends before its next run is due, and a
     * more frequent one's within half an hour.
     */
    public function test_every_scheduled_lock_ends_before_the_next_run_is_due(): void
    {
        config([
            'gaiacp.backups.enabled' => true,
            'gaiacp.automation.exchange_rates' => true,
            'queue.default' => 'database',
        ]);
        $schedule = app(Schedule::class);
        require base_path('routes/console.php');
        app(PluginManager::class)->schedule($schedule); // every bundled plugin's, enabled or not

        $events = collect($schedule->events())->filter(fn (Event $event) => $event->withoutOverlapping);
        $this->assertGreaterThan(20, $events->count());

        $from = Carbon::parse('2026-11-02 12:00:00');
        foreach ($events as $event) {
            $cron = new CronExpression($event->expression);
            $next = Carbon::instance($cron->getNextRunDate($from));
            $gap = (int) $next->diffInMinutes(Carbon::instance($cron->getNextRunDate($next)));

            $this->assertLessThan(
                max($gap, 31),
                $event->expiresAt,
                ($event->description ?: $event->command)." runs every {$gap} minutes but keeps its lock for {$event->expiresAt}",
            );
        }
    }
}
