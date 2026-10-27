<?php

namespace App\Console\Commands;

use App\ClientSessions\ClientSessions;
use App\Models\ActivityLog;
use App\Models\EmailLog;
use App\Models\IpBan;
use App\Models\ModuleLog;
use App\Monitoring\UptimeHistory;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('gaiacp:prune-logs')]
#[Description('Delete module, email and activity log rows and bell notifications older than Settings → Logging keeps them, IP bans that ended a month ago, server status checks the uptime bar no longer shows and expired cache rows')]
class PruneLogs extends Command
{
    public function handle(): int
    {
        $modules = ModuleLog::query()->where('created_at', '<', now()->subDays(max(1, (int) config('gaiacp.debug.module_log_days')))->startOfDay())->delete();
        $emails = EmailLog::query()->where('created_at', '<', now()->subDays(max(1, (int) config('gaiacp.debug.email_log_days')))->startOfDay())->delete();

        // Empty = kept for ever
        $activity = ($days = (int) config('gaiacp.debug.activity_log_days')) > 0
            ? ActivityLog::query()->where('created_at', '<', now()->subDays($days)->startOfDay())->delete() : 0;
        $notifications = ($days = (int) config('gaiacp.debug.notification_days')) > 0
            ? DB::table('notifications')->where('created_at', '<', now()->subDays($days)->startOfDay())->delete() : 0;

        // The database cache store only drops an expired row when that key is read again
        $cache = DB::table(config('cache.stores.database.table', 'cache'))->where('expiration', '<', now()->getTimestamp())->delete();

        // Bans that ended a month ago are only history (Settings → Banned IPs)
        $bans = IpBan::query()->where('expires_at', '<', now()->subDays(30))->delete();

        // Server statuses older than the uptime bar's longest range
        $checks = DB::table('server_monitor_checks')->where('checked_at', '<', now()->subDays(UptimeHistory::KEEP_DAYS))->delete();

        // Client area devices not seen for longer than a session lasts, and sign-ins older than half a year
        $signIns = app(ClientSessions::class)->prune();

        $this->info("Deleted {$modules} module log, {$emails} email log and {$activity} activity log row(s), {$notifications} bell notification(s), {$cache} expired cache row(s), {$bans} ended IP ban(s), {$checks} old server status check(s), {$signIns} old client sign-in row(s).");

        return self::SUCCESS;
    }
}
