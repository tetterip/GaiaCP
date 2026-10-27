<?php

namespace App\Console\Commands;

use App\Mail\DailySummaryMail;
use App\Models\ActivityLog;
use App\Models\User;
use App\Reports\DailySummary;
use App\Support\Locales;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

#[Signature('gaiacp:daily-summary {--dry-run : List what the email would report without sending it}')]
#[Description('Email the admins who receive system emails the payment notices created since the last summary, the overdue ones and the domain sync changes and problems')]
class SendDailySummary extends Command
{
    public const EVENT = 'admin_summary.sent';

    public function handle(): int
    {
        $until = CarbonImmutable::now();
        $dryRun = (bool) $this->option('dry-run');

        if (! config('gaiacp.automation.daily_summary')) {
            $this->info('The daily summary is off (Settings → Automation).');

            return self::SUCCESS;
        }

        // Once a day: a re-run the same day sends nothing.
        $last = ActivityLog::query()->where('event', self::EVENT)->latest('id')->first();
        if (! $dryRun && $last && $last->created_at->isSameDay($until)) {
            $this->info('The daily summary was already sent today.');

            return self::SUCCESS;
        }

        // Covers everything since the last one, so a missed day is not lost.
        $since = $last?->created_at ?? $until->subDay();
        $summary = DailySummary::between($since, $until);

        $this->line(sprintf(
            '%d new payment notice(s), %d overdue; domains: %d changed, %d failed, %d in sync.',
            $summary->newNotices->count(),
            $summary->overdueNotices->count(),
            $summary->domainChanges->count(),
            $summary->domainFailures->count(),
            $summary->domainsInSync,
        ));

        $admins = User::query()->admins()->where('active', true)->where('system_emails', true)->get();

        if ($summary->isEmpty() || $admins->isEmpty()) {
            $this->info($summary->isEmpty() ? 'Nothing to report, no email sent.' : 'No admin receives system emails.');

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->info('[dry run] Would email: '.$admins->pluck('email')->implode(', '));

            return self::SUCCESS;
        }

        $sent = [];
        $failed = 0;

        foreach ($admins as $admin) {
            try {
                Mail::to($admin->email)->locale(Locales::normalize($admin->locale))->send(new DailySummaryMail($summary));
                $sent[] = $admin->email;
            } catch (Throwable $e) {
                $failed++;
                Log::error('Daily summary email failed', ['user_id' => $admin->id, 'error' => $e->getMessage()]);
                ActivityLog::record('email.failed', "Daily summary to {$admin->email} failed: {$e->getMessage()}");
            }
        }

        if ($sent !== []) {
            ActivityLog::record(self::EVENT, 'Daily summary sent to '.implode(', ', $sent), properties: ['since' => $since->toIso8601String()]);
        }

        $this->info(sprintf('Sent to %d admin(s), %d failed.', count($sent), $failed));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
