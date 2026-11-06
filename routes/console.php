<?php

use App\Plugins\PluginManager;
use App\Support\Demo;
use Illuminate\Console\Scheduling\Schedule as Scheduler;
use Illuminate\Support\Facades\Schedule;

// withoutOverlapping() always names its minutes: a run that dies (killed, out of memory, server restart) leaves its
// lock behind until they pass, and Laravel's default of 24 hours would stop an every-minute command for a day, or skip
// a daily one's next run. So frequent commands are free again within half an hour (10 minutes for every minute, 15 for
// every five), hourly ones after 55 minutes and daily ones after 12 hours (ScheduleTest checks every command).

Schedule::command('gaiacp:send-reminders')
    ->dailyAt(config('gaiacp.reminders.send_at'))
    ->withoutOverlapping(720)
    ->onOneServer();

Schedule::command('gaiacp:suspend-overdue')
    ->dailyAt(config('gaiacp.reminders.send_at'))
    ->withoutOverlapping(720)
    ->onOneServer();

Schedule::command('gaiacp:sync-domains')
    ->dailyAt('04:00')
    ->withoutOverlapping(720)
    ->onOneServer();

Schedule::command('gaiacp:terminate-scheduled')
    ->dailyAt(config('gaiacp.reminders.send_at'))
    ->withoutOverlapping(720)
    ->onOneServer();

Schedule::command('gaiacp:cancel-unpaid-orders')
    ->dailyAt(config('gaiacp.reminders.send_at'))
    ->withoutOverlapping(720)
    ->onOneServer();

// After the ones above (same time, run in order), so it reports today's notices.
Schedule::command('gaiacp:daily-summary')
    ->dailyAt(config('gaiacp.reminders.send_at'))
    ->withoutOverlapping(720)
    ->onOneServer();

// Settings → Automation → Compare servers nightly (the command checks the switch)
Schedule::command('gaiacp:compare-servers')
    ->dailyAt('04:30')
    ->withoutOverlapping(720)
    ->onOneServer();

// Messages to clients: the next few emails every minute, so no queue worker is needed
Schedule::command('gaiacp:send-client-messages')
    ->everyMinute()
    ->withoutOverlapping(10)
    ->onOneServer();

// Jobs on the queue (a plugin's, Retry on Settings → Failed jobs) without a queue worker: run what is waiting, then stop
if (config('queue.default') !== 'sync') {
    Schedule::command('queue:work --stop-when-empty --max-time=50 --tries=3')
        ->everyMinute()
        ->withoutOverlapping(10)
        ->onOneServer();
}

// Services → Module queue: failed set ups, unsuspends and suspensions tried again (Settings → Automation → Retry failed actions)
Schedule::command('gaiacp:retry-module-queue')
    ->everyFifteenMinutes()
    ->withoutOverlapping(30)
    ->onOneServer();

// Slack / Discord messages queued by the chat plugins (nothing to do while none is on)
Schedule::command('gaiacp:send-chat-messages')
    ->everyMinute()
    ->withoutOverlapping(10)
    ->onOneServer();

// Settings → Automation → Usage warning (the command checks it is set)
Schedule::command('gaiacp:check-usage')
    ->dailyAt('06:00')
    ->withoutOverlapping(720)
    ->onOneServer();

// Settings → Support → Close tickets after (the command checks it is set)
Schedule::command('gaiacp:close-tickets')
    ->dailyAt('07:00')
    ->withoutOverlapping(720)
    ->onOneServer();

// Clients → Leads: the bell for the leads to contact today
Schedule::command('gaiacp:lead-follow-ups')
    ->dailyAt('08:00')
    ->withoutOverlapping(720)
    ->onOneServer();

// Settings → Domains → Update registrar costs daily (the command checks it is on)
Schedule::command('gaiacp:sync-tld-costs')
    ->dailyAt('04:15')
    ->withoutOverlapping(720)
    ->onOneServer();

// Billing → Quotes: sent quotes past their valid until date expire
Schedule::command('gaiacp:expire-quotes')
    ->dailyAt('00:20')
    ->withoutOverlapping(720)
    ->onOneServer();

// SSL certificates waiting for validation, and the ones to reissue or renew (nothing to do without any)
Schedule::command('gaiacp:check-certificates')
    ->hourly()
    ->withoutOverlapping(55)
    ->onOneServer();

Schedule::command('gaiacp:prune-logs')
    ->dailyAt('03:30')
    ->withoutOverlapping(720)
    ->onOneServer();

// Settings → Updates: the bell (and the daily summary) once for each new GaiaCP release
Schedule::command('gaiacp:check-for-update')
    ->dailyAt('05:50')
    ->withoutOverlapping(720)
    ->onOneServer();

// Settings → Plugins → Plugin catalogue: a fresh copy, and the bell once for each new version of an installed plugin
Schedule::command('gaiacp:check-plugin-catalogue')
    ->dailyAt('05:40')
    ->withoutOverlapping(720)
    ->onOneServer();

// Settings → Automation → Update exchange rates daily; the ECB publishes around 16:00 CET.
if (config('gaiacp.automation.exchange_rates')) {
    Schedule::command('gaiacp:update-exchange-rates')
        ->dailyAt('17:00')
        ->withoutOverlapping(720)
        ->onOneServer();
}

if (config('gaiacp.backups.enabled')) {
    $backup = Schedule::command('gaiacp:backup')
        ->withoutOverlapping(720)
        ->onOneServer();

    config('gaiacp.backups.frequency') === 'weekly'
        ? $backup->weeklyOn((int) config('gaiacp.backups.weekday'), config('gaiacp.backups.time'))
        : $backup->dailyAt(config('gaiacp.backups.time'));
}

// Settings → Automation → Ping URL: tells a monitoring service outside GaiaCP that cron runs (nothing to do without one)
Schedule::command('gaiacp:heartbeat')
    ->everyFiveMinutes()
    ->withoutOverlapping(15)
    ->onOneServer();

// Servers linked to a monitor (Uptime Kuma, …): does nothing while none is.
Schedule::command('gaiacp:check-monitors')
    ->everyFiveMinutes()
    ->withoutOverlapping(15)
    ->onOneServer();

// The public demo: its made-up data put back every few minutes (GAIACP_DEMO_RESET_MINUTES)
if (Demo::enabled()) {
    Schedule::command('gaiacp:demo-reset')
        ->cron(Demo::cron())
        ->withoutOverlapping(30)
        ->onOneServer();
}

// Plugins' own scheduled commands (e.g. gaiacp:check-blacklists hourly)
app(PluginManager::class)->schedule(app(Scheduler::class));
