<?php

namespace App\Reports;

use App\Enums\ServiceType;
use App\Models\Payment;
use App\Models\Product;
use App\Support\Currencies;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;

/**
 * Income is what was received: the recorded payments, as entered (tax included when a notice charged it).
 * The totals are summed in SQL per day and currency (no date functions, so it reads the same on SQLite and MySQL) and
 * folded into months or days here; only the transaction list loads the payments themselves. Payments in another
 * currency count in the base one at today's rate (Settings → Currencies), each payment rounded on its own as before:
 * those are grouped by amount too.
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

        foreach ($this->totals(Payment::query(), CarbonImmutable::create($year), CarbonImmutable::create($year)->endOfYear(), 'payments.paid_on') as $row) {
            $month = CarbonImmutable::parse($row->paid_on)->month;
            $months[$month]['count'] += $row->count;
            $months[$month]['cents'] += $row->cents;
        }

        return $months;
    }

    /** @return array<int, array{count: int, cents: int}> day of month => payments received that day */
    public function daily(int $year, int $month): array
    {
        $start = CarbonImmutable::create($year, $month);
        $days = array_fill_keys(range(1, $start->daysInMonth), ['count' => 0, 'cents' => 0]);

        foreach ($this->totals(Payment::query(), $start, $start->endOfMonth(), 'payments.paid_on') as $row) {
            $day = CarbonImmutable::parse($row->paid_on)->day;
            $days[$day]['count'] += $row->count;
            $days[$day]['cents'] += $row->cents;
        }

        return $days;
    }

    /**
     * Base currency income per day with payments, from $from to $until (whole days).
     *
     * @return array<string, int> Y-m-d => cents
     */
    public function perDay(CarbonInterface $from, CarbonInterface $until): array
    {
        $days = [];

        foreach ($this->totals(Payment::query(), $from, $until, 'payments.paid_on') as $row) {
            $day = CarbonImmutable::parse($row->paid_on)->toDateString();
            $days[$day] = ($days[$day] ?? 0) + $row->cents;
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
        $query = Payment::query()->join('services', 'services.id', '=', 'payments.service_id');
        $groups = [];

        foreach ($this->totals($query, $from, $until, 'services.product_id', 'services.type') as $row) {
            $key = $row->product_id ? 'product:'.$row->product_id : 'type:'.$row->type;
            $groups[$key] ??= ['product_id' => $row->product_id, 'type' => ServiceType::from($row->type), 'count' => 0, 'cents' => 0];
            $groups[$key]['count'] += $row->count;
            $groups[$key]['cents'] += $row->cents;
        }

        $names = Product::query()->whereIn('id', array_filter(array_column($groups, 'product_id')))->pluck('name', 'id');

        return collect($groups)
            ->map(fn (array $group) => [
                'product' => $group['product_id'] ? ($names[$group['product_id']] ?? __('No product')) : __('No product'),
                'type' => $group['type']->getLabel(),
                'count' => $group['count'],
                'cents' => $group['cents'],
            ])
            ->sortByDesc('cents')
            ->values();
    }

    /** @return Collection<int, Payment> oldest first, with service, client and product */
    public function between(CarbonInterface $from, CarbonInterface $until): Collection
    {
        return self::paidBetween(Payment::query()->with(['client', 'service.client', 'service.product']), $from, $until)
            ->orderBy('paid_on')
            ->orderBy('id')
            ->get();
    }

    /**
     * Payments with paid_on from $from to $until (whole days) as a plain range, which can use the paid_on index
     * (whereDate() wraps the column in a function, which cannot).
     *
     * @template TQuery of Builder|EloquentBuilder<*>
     *
     * @param  TQuery  $query
     * @return TQuery
     */
    public static function paidBetween(Builder|EloquentBuilder $query, CarbonInterface $from, CarbonInterface $until, string $column = 'payments.paid_on'): Builder|EloquentBuilder
    {
        return $query->where($column, '>=', $from->toDateString())
            ->where($column, '<', CarbonImmutable::parse($until)->addDay()->toDateString());
    }

    /**
     * Count and base currency total of the payments in a period, per value of the $by columns. Summed in SQL per
     * currency; a payment in another currency is converted on its own (grouped by amount), so the totals match adding
     * up the payments one by one.
     *
     * @param  EloquentBuilder<Payment>  $query
     * @return list<object> rows with the $by columns (without their table), count and cents
     */
    private function totals(EloquentBuilder $query, CarbonInterface $from, CarbonInterface $until, string ...$by): array
    {
        $base = Currencies::base();

        $rows = self::paidBetween($query->toBase(), $from, $until)
            ->select($by)
            ->addSelect('payments.currency')
            ->selectRaw('case when payments.currency = ? then 0 else payments.amount_cents end as each_cents', [$base])
            ->selectRaw('count(*) as payments_count, sum(payments.amount_cents) as total_cents')
            ->groupBy([...$by, 'payments.currency', 'each_cents'])
            ->get();

        $grouped = [];

        foreach ($rows as $row) {
            $key = implode("\0", array_map(fn (string $column) => (string) $row->{self::column($column)}, $by));
            $cents = (int) $row->each_cents === 0 && strtoupper((string) $row->currency) === $base
                ? Currencies::toBase((int) $row->total_cents, $row->currency)
                : Currencies::toBase((int) $row->each_cents, $row->currency) * (int) $row->payments_count;

            $grouped[$key] ??= (object) [...array_combine(array_map(self::column(...), $by), array_map(fn (string $c) => $row->{self::column($c)}, $by)), 'count' => 0, 'cents' => 0];
            $grouped[$key]->count += (int) $row->payments_count;
            $grouped[$key]->cents += $cents;
        }

        return array_values($grouped);
    }

    private static function column(string $column): string
    {
        return str_contains($column, '.') ? substr($column, strrpos($column, '.') + 1) : $column;
    }
}
