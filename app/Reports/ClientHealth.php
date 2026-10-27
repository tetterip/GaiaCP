<?php

namespace App\Reports;

use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\Payment;
use App\Support\Currencies;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * How a client pays, for the "Payment record" box on the client page: income from them, how late they pay on average
 * over the last 12 months, and how often they were late or suspended. Helps decide whom to give a few more days.
 *
 * The delay of a payment is the days between the due date it covered and the day it was paid (early = 0); one payment
 * notice paid for several services counts once. A notice still unpaid past its due date counts with the days so far,
 * so a client who stopped paying does not look punctual.
 */
final class ClientHealth
{
    /** Average delay up to this many days: on time. */
    public const ON_TIME_DAYS = 3;

    /** Up to this many: watch; more: pays late. */
    public const WATCH_DAYS = 14;

    /**
     * The rating is on_time, watch or late; null without a payment or overdue notice in the last 12 months.
     *
     * @return array{income: string, incomeYear: string, rating: ?string, averageDays: ?int, late: int, suspensions: int}
     */
    public static function for(Client $client, ?CarbonInterface $today = null): array
    {
        $today = CarbonImmutable::parse($today ?? today())->startOfDay();
        $since = $today->subYear();

        $payments = $client->payments()->get(['amount_cents', 'currency', 'paid_on', 'due_date_before']);
        $recent = $payments->filter(fn (Payment $p) => ! $p->paid_on->lessThan($since));

        $delays = $recent
            ->filter(fn (Payment $p) => $p->due_date_before !== null)
            ->unique(fn (Payment $p) => $p->paid_on->toDateString().'|'.$p->due_date_before->toDateString())
            ->map(fn (Payment $p) => max(0, (int) $p->due_date_before->diffInDays($p->paid_on, false)))
            ->values();

        $overdue = $client->invoices()->unpaid()->whereDate('due_date', '<', $today)->pluck('due_date')
            ->map(fn ($due) => (int) CarbonImmutable::parse($due)->diffInDays($today));

        $all = $delays->concat($overdue);
        $average = $all->isEmpty() ? null : (int) round($all->avg());

        return [
            'income' => self::sum($payments, $client),
            'incomeYear' => self::sum($recent, $client),
            'rating' => match (true) {
                $average === null => null,
                $average <= self::ON_TIME_DAYS => 'on_time',
                $average <= self::WATCH_DAYS => 'watch',
                default => 'late',
            },
            'averageDays' => $average,
            'late' => $delays->filter(fn (int $days) => $days > 0)->count() + $overdue->count(),
            'suspensions' => ActivityLog::query()
                ->where('client_id', $client->id)
                ->where('event', 'service.suspended')
                ->where('created_at', '>=', $since)
                ->count(),
        ];
    }

    /** One amount per currency, like the open balance. */
    private static function sum(Collection $payments, Client $client): string
    {
        return $payments->isEmpty()
            ? Money::format(0, Currencies::forClient($client))
            : $payments->groupBy('currency')->map(fn ($group, $currency) => Money::format((int) $group->sum('amount_cents'), $currency))->join(' + ');
    }
}
