<?php

namespace App\Reports;

use App\Enums\InvoiceStatus;
use App\Models\ActivityLog;
use App\Models\Invoice;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * What the admins' daily summary email reports for one period: payment notices created in it,
 * every payment notice overdue at its end, and the domain sync results logged in it. Built from
 * the database and the activity log only, so it can be made again at any time.
 */
final readonly class DailySummary
{
    /**
     * @param  Collection<int, Invoice>  $newNotices
     * @param  Collection<int, Invoice>  $overdueNotices
     * @param  Collection<int, ActivityLog>  $domainChanges  domain.synced entries whose expiry or nameservers changed
     * @param  Collection<int, ActivityLog>  $domainFailures  failed domain syncs
     * @param  Collection<int, ActivityLog>  $unmatchedPayments  online payments received but not recorded (payment.online_unmatched)
     */
    public function __construct(
        public CarbonImmutable $since,
        public CarbonImmutable $until,
        public Collection $newNotices,
        public Collection $overdueNotices,
        public Collection $domainChanges,
        public Collection $domainFailures,
        public int $domainsInSync,
        public Collection $unmatchedPayments = new Collection,
    ) {}

    public static function between(CarbonImmutable $since, CarbonImmutable $until): self
    {
        $newNotices = Invoice::query()
            ->with('client')
            ->where('created_at', '>', $since)
            ->where('created_at', '<=', $until)
            ->where('status', '!=', InvoiceStatus::Cancelled)
            ->orderBy('number')
            ->get();

        $overdueNotices = Invoice::query()
            ->with('client')
            ->unpaid()
            ->where('due_date', '<', $until->startOfDay()->toDateString())
            ->orderBy('due_date')
            ->orderBy('number')
            ->get();

        $logs = ActivityLog::query()
            ->with(['service', 'client'])
            ->where('created_at', '>', $since)
            ->where('created_at', '<=', $until)
            ->whereIn('event', ['domain.synced', 'provisioning.failed', 'payment.online_unmatched'])
            ->orderBy('id')
            ->get();

        $synced = $logs->where('event', 'domain.synced');
        // provisioning.failed is shared by every panel and registrar call; only the syncs belong here.
        $failures = $logs->where('event', 'provisioning.failed')->filter(fn (ActivityLog $log) => ($log->properties['event'] ?? null) === 'domain.synced');

        // A domain synced twice (the daily run and a click on Sync) is reported once: its last result wins.
        $last = $synced->concat($failures)->sortBy('id')->keyBy(fn (ActivityLog $log) => $log->service_id ?? 'log-'.$log->id);
        $changes = $synced->filter(fn (ActivityLog $log) => filled($log->properties['changes'] ?? null))->keyBy('service_id');

        return new self(
            $since,
            $until,
            $newNotices,
            $overdueNotices,
            domainChanges: $changes->values(),
            domainFailures: $last->filter(fn (ActivityLog $log) => $log->event === 'provisioning.failed')->values(),
            domainsInSync: $last->filter(fn (ActivityLog $log) => $log->event === 'domain.synced' && ! $changes->has($log->service_id))->count(),
            unmatchedPayments: $logs->where('event', 'payment.online_unmatched')->values(),
        );
    }

    /** Nothing new, nothing overdue, no domain changed or failed and no online payment to check: no email. */
    public function isEmpty(): bool
    {
        return $this->unmatchedPayments->isEmpty()
            && $this->newNotices->isEmpty()
            && $this->overdueNotices->isEmpty()
            && $this->domainChanges->isEmpty()
            && $this->domainFailures->isEmpty();
    }
}
