<?php

namespace App\Support;

use App\Enums\TicketStatus;
use App\Models\ActivityLog;
use App\Models\EmailLog;
use App\Models\ModuleLog;
use App\Models\TicketAttachment;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * Settings → Logging → Clean up, and the files part of gaiacp:prune-logs: delete log rows older than a date now, delete the
 * files of closed tickets, empty the cache. Each manual run is written to the activity log (after deleting, so it stays).
 */
class LogCleanup
{
    /** The activity log is the audit trail: by hand only rows older than this many days go, as in Settings → Logging. */
    public const ACTIVITY_MIN_DAYS = 30;

    /** @return array<string, string> key => English name, in the order shown */
    public static function logs(): array
    {
        return [
            'module' => 'Module log',
            'email' => 'Email log',
            'activity' => 'Activity log',
            'notifications' => 'Bell notifications',
        ];
    }

    public function count(string $log): int
    {
        return $this->query($log)->count();
    }

    /** The day an older-than run starts with: what Settings → Logging keeps, or the earliest allowed. */
    public function defaultBefore(string $log): CarbonInterface
    {
        $days = match ($log) {
            'module' => (int) config('gaiacp.debug.module_log_days'),
            'email' => (int) config('gaiacp.debug.email_log_days'),
            'activity' => (int) config('gaiacp.debug.activity_log_days'),
            'notifications' => (int) config('gaiacp.debug.notification_days'),
            default => throw new InvalidArgumentException("Unknown log {$log}"),
        };

        $days = max($log === 'activity' ? self::ACTIVITY_MIN_DAYS : 1, $days ?: 90);

        return now()->subDays($days)->startOfDay();
    }

    /** The latest day an older-than run may name for this log (today for all but the activity log). */
    public static function latestBefore(string $log): CarbonInterface
    {
        return $log === 'activity' ? now()->subDays(self::ACTIVITY_MIN_DAYS)->startOfDay() : now()->startOfDay();
    }

    /** Delete the rows of a log written before the start of that day. */
    public function deleteBefore(string $log, CarbonInterface $before): int
    {
        if ($before->greaterThan(self::latestBefore($log))) {
            throw new InvalidArgumentException("Too recent a day for the {$log} log");
        }

        $before = $before->copy()->startOfDay();
        $deleted = $this->query($log)->where('created_at', '<', $before)->delete();

        ActivityLog::record('log.cleaned', "Deleted {$deleted} row(s) of the ".strtolower(self::logs()[$log]).' older than '.$before->toDateString(), properties: [
            'log' => $log,
            'before' => $before->toDateString(),
            'deleted' => $deleted,
        ]);

        return $deleted;
    }

    /** Files of closed tickets that are still on the disk, closed before that time. */
    public function attachments(?CarbonInterface $closedBefore = null): \Illuminate\Database\Eloquent\Builder
    {
        return TicketAttachment::query()
            ->whereNull('removed_at')
            ->whereHas('message.ticket', fn ($ticket) => $ticket
                ->where('status', TicketStatus::Closed)
                ->when($closedBefore, fn ($query) => $query->where('closed_at', '<', $closedBefore)));
    }

    /**
     * Delete the files of tickets closed before that time; the message keeps their name, marked as deleted. Safe to
     * re-run: a removed file is skipped.
     *
     * @return array{files: int, bytes: int}
     */
    public function deleteAttachments(CarbonInterface $closedBefore, bool $manual = false): array
    {
        $files = 0;
        $bytes = 0;

        $this->attachments($closedBefore)->chunkById(200, function ($attachments) use (&$files, &$bytes) {
            foreach ($attachments as $attachment) {
                Storage::disk('local')->delete($attachment->path);
                $attachment->forceFill(['removed_at' => now()])->save();
                $files++;
                $bytes += $attachment->size;
            }
        });

        if ($files > 0 || $manual) {
            ActivityLog::record('ticket.files_deleted', "Deleted {$files} file(s) of tickets closed before ".$closedBefore->toDateString(), properties: [
                'before' => $closedBefore->toDateString(),
                'files' => $files,
                'bytes' => $bytes,
            ]);
        }

        return ['files' => $files, 'bytes' => $bytes];
    }

    /** What the nightly run deletes: Settings → Support → Delete files of closed tickets after (empty = never). */
    public function pruneAttachments(): int
    {
        $days = (int) config('gaiacp.support.attachment_days');

        return $days > 0 ? $this->deleteAttachments(now()->subDays($days))['files'] : 0;
    }

    /** Empty the application cache and the compiled views; both fill again as pages are opened. */
    public function clearCache(): void
    {
        Artisan::call('cache:clear');
        Artisan::call('view:clear');

        ActivityLog::record('cache.cleared', 'Emptied the cache and the compiled views');
    }

    private function query(string $log): \Illuminate\Database\Eloquent\Builder|Builder
    {
        return match ($log) {
            'module' => ModuleLog::query(),
            'email' => EmailLog::query(),
            'activity' => ActivityLog::query(),
            'notifications' => DB::table('notifications'),
            default => throw new InvalidArgumentException("Unknown log {$log}"),
        };
    }
}
