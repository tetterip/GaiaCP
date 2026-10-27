<?php

namespace App\Reports;

use App\Models\Payment;
use App\Support\Currencies;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Income is what was received: the recorded payments, as entered (tax included when a notice charged it).
 * Grouped in PHP rather than with SQL date functions, so it reads the same on SQLite and MySQL. Payments in another
 * currency count in the base one at today's rate (Settings → Currencies).
 */
final class IncomeReport
{
    /** @return list<int> years with payments, newest first, always including this year */
    public function years(): array
    {
        $first = Payment::query()->min('paid_on');
        $from = $first ? CarbonImmutable::parse($first)->year : today()->year;

        return array_reverse(range(min($from, today()->year), today()->year));
    }

    /** @return array<int, array{count: int, cents: int}> month (1-12) => payments received that month */
    public function monthly(int $year): array
    {
        $months = array_fill_keys(range(1, 12), ['count' => 0, 'cents' => 0]);

        foreach ($this->between(CarbonImmutable::create($year), CarbonImmutable::create($year)->endOfYear()) as $payment) {
            $months[$payment->paid_on->month]['count']++;
            $months[$payment->paid_on->month]['cents'] += Currencies::toBase($payment->amount_cents, $payment->currency);
        }

        return $months;
    }

    /** @return array<int, array{count: int, cents: int}> day of month => payments received that day */
    public function daily(int $year, int $month): array
    {
        $start = CarbonImmutable::create($year, $month);
        $days = array_fill_keys(range(1, $start->daysInMonth), ['count' => 0, 'cents' => 0]);

        foreach ($this->between($start, $start->endOfMonth()) as $payment) {
            $days[$payment->paid_on->day]['count']++;
            $days[$payment->paid_on->day]['cents'] += Currencies::toBase($payment->amount_cents, $payment->currency);
        }

        return $days;
    }

    /**
     * Payments in a period by the product of their service (services without one by their type), largest first.
     *
     * @return Collection<int, array{product: string, type: string, count: int, cents: int}>
     */
    public function byProduct(CarbonInterface $from, CarbonInterface $until): Collection
    {
        return $this->between($from, $until)
            ->groupBy(fn (Payment $p) => $p->service->product_id ? 'product:'.$p->service->product_id : 'type:'.$p->service->type->value)
            ->map(fn (Collection $payments) => [
                'product' => $payments->first()->service->product?->name ?? __('No product'),
                'type' => $payments->first()->service->type->getLabel(),
                'count' => $payments->count(),
                'cents' => (int) $payments->sum(fn (Payment $p) => Currencies::toBase($p->amount_cents, $p->currency)),
            ])
            ->sortByDesc('cents')
            ->values();
    }

    /** @return Collection<int, Payment> oldest first, with service, client and product */
    public function between(CarbonInterface $from, CarbonInterface $until): Collection
    {
        return Payment::query()
            ->with(['client', 'service.client', 'service.product'])
            ->whereDate('paid_on', '>=', $from->toDateString())
            ->whereDate('paid_on', '<=', $until->toDateString())
            ->orderBy('paid_on')
            ->orderBy('id')
            ->get();
    }
}
