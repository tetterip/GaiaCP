<?php

namespace App\Listeners;

use App\Support\Health\SchedulerMonitor;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;

/**
 * Feeds SchedulerMonitor: every cron `schedule:run`, and every real (not --dry-run) daily gaiacp:* run.
 * Discovered automatically (app/Listeners).
 */
class RecordCommandRuns
{
    public function __construct(private SchedulerMonitor $monitor) {}

    public function handleStarting(CommandStarting $event): void
    {
        if ($event->command === 'schedule:run') {
            rescue(fn () => $this->monitor->heartbeat()); // never let monitoring stop the automation
        }
    }

    public function handleFinished(CommandFinished $event): void
    {
        if (in_array($event->command, SchedulerMonitor::DAILY_COMMANDS, true) && ! $event->input->hasParameterOption('--dry-run')) {
            rescue(fn () => $this->monitor->commandFinished($event->command, $event->exitCode));
        }
    }
}
