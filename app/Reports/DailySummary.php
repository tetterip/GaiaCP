<?php

namespace App\Reports;

use App\Enums\InvoiceStatus;
use App\Models\ActivityLog;
use App\Models\Invoice;
use App\Models\ModuleQueueItem;
use App\Models\Payment;
use App\Support\Currencies;
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
     * @param  Collection<int, ActivityLog>  $updates  new GaiaCP releases found (gaiacp.update_available)
     * @param  Collection<int, ActivityLog>  $pluginUpdates  new versions of uploaded plugins in the catalogue (plugin.update_available, plugin.update_waiting)
     * @param  Collection<int, ModuleQueueItem>  $moduleQueue  failed panel / registrar actions still open at its end
     * @param  array<string, int>  $collections  base currency received per day (Y-m-d => cents), the CHART_DAYS days up to the day before it ends, oldest first
     * @param  int  $paymentsYesterday  payments received the day before it ends (refunds not counted)
     * @param  Collection<int, Invoice>  $upcomingNotices  unpaid payment notices due in the UPCOMING_DAYS days from the day it ends
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
        public Collection $updates = new Collection,
        public Collection $moduleQueue = new Collection,
        public Collection $pluginUpdates = new Collection,
        public array $collections = [],
        public int $paymentsYesterday = 0,
        public Collection $upcomingNotices = new Collection,
    ) {}

    /** Days in the collections chart. */
    public const CHART_DAYS = 14;

    /** Days ahead counted as coming in (Due in the next 7 days). */
    public const UPCOMING_DAYS = 7;

    /** Overdue age groups: the most days in each, the last one open ended. */
    public const AGES = [7, 30, null];

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
            ->withOwed()
            ->where('due_date', '<', $until->startOfDay()->toDateString())
            ->orderBy('due_date')
            ->orderBy('number')
            ->get();

        $logs = ActivityLog::query()
            ->with(['service', 'client'])
            ->where('created_at', '>', $since)
            ->where('created_at', '<=', $until)
            ->whereIn('event', ['domain.synced', 'provisioning.failed', 'payment.online_unmatched', 'gaiacp.update_available', 'plugin.update_available', 'plugin.update_waiting'])
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
            updates: $logs->where('event', 'gaiacp.update_available')->values(),
            moduleQueue: ModuleQueueItem::query()->open()->with('service.client')->orderBy('id')->get(),
            pluginUpdates: $logs->whereIn('event', ['plugin.update_available', 'plugin.update_waiting'])->values(),
            collections: self::collections($until),
            paymentsYesterday: IncomeReport::paidBetween(Payment::query(), $yesterday = $until->subDay()->startOfDay(), $yesterday)
                ->whereNull('refund_of')
                ->count(),
            upcomingNotices: Invoice::query()
                ->with('client')
                ->unpaid()
                ->withOwed()
                ->where('due_date', '>=', $until->toDateString())
                ->where('due_date', '<', $until->addDays(self::UPCOMING_DAYS)->toDateString())
                ->orderBy('due_date')
                ->get(),
        );
    }

    /** @return array<string, int> every day of the chart, ending the day before $until, with what came in (0 when nothing) */
    private static function collections(CarbonImmutable $until): array
    {
        $last = $until->subDay()->startOfDay();
        $first = $last->subDays(self::CHART_DAYS - 1);
        $received = (new IncomeReport)->perDay($first, $last);
        $days = [];

        for ($day = $first; $day <= $last; $day = $day->addDay()) {
            $days[$day->toDateString()] = $received[$day->toDateString()] ?? 0;
        }

        return $days;
    }

    /** Base currency received the day before it ends. */
    public function collectedYesterday(): int
    {
        return (int) (array_values($this->collections)[count($this->collections) - 1] ?? 0);
    }

    /** Base currency total of the chart's days. */
    public function collectedInChart(): int
    {
        return array_sum($this->collections);
    }

    /** What the overdue notices still ask, in the base currency. */
    public function overdueTotal(): int
    {
        return $this->overdueNotices->sum(fn (Invoice $invoice) => Currencies::toBase((int) $invoice->owed_cents, $invoice->currency));
    }

    /** What the notices due in the next days still ask, in the base currency. */
    public function upcomingTotal(): int
    {
        return $this->upcomingNotices->sum(fn (Invoice $invoice) => Currencies::toBase((int) $invoice->owed_cents, $invoice->currency));
    }

    /** Whole days a notice is overdue when the summary ends. */
    public function daysOverdue(Invoice $invoice): int
    {
        return (int) $invoice->due_date->diffInDays($this->until->startOfDay());
    }

    /**
     * The overdue amount by age, one row per AGES group (empty ones included).
     *
     * @return list<array{from: int, to: int|null, count: int, cents: int}>
     */
    public function overdueByAge(): array
    {
        $groups = [];
        $from = 1;

        foreach (self::AGES as $to) {
            $groups[] = ['from' => $from, 'to' => $to, 'count' => 0, 'cents' => 0];
            $from = ($to ?? 0) + 1;
        }

        foreach ($this->overdueNotices as $invoice) {
            $days = $this->daysOverdue($invoice);
            foreach ($groups as $i => $group) {
                if ($group['to'] === null || $days <= $group['to']) {
                    $groups[$i]['count']++;
                    $groups[$i]['cents'] += Currencies::toBase((int) $invoice->owed_cents, $invoice->currency);
                    break;
                }
            }
        }

        return $groups;
    }

    /** 0, 1 or 2 for an overdue age group (the colour of its bar and chips). */
    public function ageGroup(int $days): int
    {
        foreach (self::AGES as $i => $to) {
            if ($to === null || $days <= $to) {
                return $i;
            }
        }

        return count(self::AGES) - 1;
    }

    /** Problems an admin should look at today; none = all is well. */
    public function needsAttention(): bool
    {
        return $this->overdueNotices->isNotEmpty()
            || $this->moduleQueue->isNotEmpty()
            || $this->unmatchedPayments->isNotEmpty()
            || $this->domainFailures->isNotEmpty();
    }

    /** Nothing new, nothing overdue, no domain changed or failed, no online payment to check, nothing in the module queue and no new release of GaiaCP or a plugin: no email. */
    public function isEmpty(): bool
    {
        return $this->unmatchedPayments->isEmpty()
            && $this->moduleQueue->isEmpty()
            && $this->updates->isEmpty()
            && $this->pluginUpdates->isEmpty()
            && $this->newNotices->isEmpty()
            && $this->overdueNotices->isEmpty()
            && $this->domainChanges->isEmpty()
            && $this->domainFailures->isEmpty();
    }
}
