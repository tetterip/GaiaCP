<?php

namespace App\Filament\Widgets;

use App\Enums\OrderStatus;
use App\Filament\Resources\Payments\PaymentResource;
use App\Filament\Widgets\Concerns\FitsDashboard;
use App\Models\Order;
use App\Models\Payment;
use App\Reports\IncomeReport;
use App\Support\Currencies;
use App\Support\Money;
use App\Support\Region;
use Carbon\CarbonImmutable;
use Filament\Widgets\Widget;
use Livewire\Attributes\Session;

/**
 * New and completed orders (bars) and income (line, base currency) per hour today, per day for 7 or 30 days, or per
 * month for a year, with the income compared to the period before (today: yesterday until the same time). The chosen
 * period is kept in the session. Drawn as plain SVG, no chart library.
 */
class ActivityChart extends Widget
{
    use FitsDashboard;

    public const PERIODS = ['today', '7d', '30d', '12m'];

    protected static bool $isLazy = false;

    protected static ?int $sort = 2;

    protected string $view = 'filament.dashboard.activity-chart';

    #[Session(key: 'dashboard.activity_period')]
    public string $period = '30d';

    public static function dashboardLabel(): string
    {
        return __('Orders and income');
    }

    public static function canView(): bool
    {
        return PaymentResource::canAccess();
    }

    public function setPeriod(string $period): void
    {
        if (in_array($period, self::PERIODS, true)) {
            $this->period = $period;
        }
    }

    /** @return array<string, string> */
    public static function periodLabels(): array
    {
        return ['today' => __('Today'), '7d' => __('7 days'), '30d' => __('30 days'), '12m' => __('1 year')];
    }

    /**
     * One bucket per hour, day or month, oldest first; `future` = today's hours still to come.
     *
     * @return list<array{start: CarbonImmutable, end: CarbonImmutable, label: string, future?: bool}>
     */
    public function buckets(?string $period = null, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $period ??= $this->period;

        if ($period === 'today') {
            $now = CarbonImmutable::now();

            return collect(range(0, 23))->map(fn (int $hour) => [
                'start' => $start = $today->startOfDay()->addHours($hour),
                'end' => $start->endOfHour(),
                'label' => $start->format('H:00'),
                'future' => $start > $now,
            ])->all();
        }

        if ($period === '12m') {
            return collect(range(11, 0))->map(fn (int $ago) => [
                'start' => $month = $today->startOfMonth()->subMonths($ago),
                'end' => $month->endOfMonth(),
                'label' => $month->locale(app()->getLocale())->translatedFormat('M'),
            ])->all();
        }

        $days = $period === '7d' ? 7 : 30;

        return collect(range($days - 1, 0))->map(fn (int $ago) => [
            'start' => $day = $today->subDays($ago),
            'end' => $day->endOfDay(),
            'label' => $day->format(Region::shortDateFormat()),
        ])->all();
    }

    /**
     * Orders, completed orders and income (cents, base currency) in each bucket.
     *
     * @return list<array{label: string, orders: int, completed: int, income: int}>
     */
    public function series(?string $period = null, ?CarbonImmutable $today = null): array
    {
        $buckets = $this->buckets($period, $today);
        $from = $buckets[0]['start'];
        $to = end($buckets)['end'];

        $series = array_map(fn (array $bucket) => ['label' => $bucket['label'], 'orders' => 0, 'completed' => 0, 'income' => 0], $buckets);
        $at = self::locator($buckets);

        // One pass over each small projection: the bucket of every order and payment, found by its start
        foreach (Order::query()->whereBetween('created_at', [$from, $to])->toBase()->pluck('created_at') as $created) {
            if (($i = $at(CarbonImmutable::parse($created))) !== null) {
                $series[$i]['orders']++;
            }
        }

        foreach (Order::query()->where('status', OrderStatus::Completed)->whereBetween('completed_at', [$from, $to])->toBase()->pluck('completed_at') as $completed) {
            if (($i = $at(CarbonImmutable::parse($completed))) !== null) {
                $series[$i]['completed']++;
            }
        }

        if (($period ?? $this->period) === 'today') {
            // Hours: each payment of the day at the hour it was recorded
            foreach (IncomeReport::paidBetween(Payment::query(), $from, $to)->get(['paid_on', 'amount_cents', 'currency', 'created_at']) as $payment) {
                if (($i = $at(self::paidAt($payment))) !== null) {
                    $series[$i]['income'] += Currencies::toBase($payment->amount_cents, $payment->currency);
                }
            }
        } else {
            // Days and months: the day's total, summed in SQL
            foreach (app(IncomeReport::class)->perDay($from, $to) as $day => $cents) {
                if (($i = $at(CarbonImmutable::parse($day))) !== null) {
                    $series[$i]['income'] += $cents;
                }
            }
        }

        return $series;
    }

    /**
     * The index of the bucket a moment falls in (null = none), by a binary search over the buckets' starts.
     *
     * @param  list<array{start: CarbonImmutable, end: CarbonImmutable}>  $buckets
     * @return \Closure(CarbonImmutable): ?int
     */
    private static function locator(array $buckets): \Closure
    {
        $starts = array_map(fn (array $bucket) => $bucket['start']->getTimestamp(), $buckets);
        $ends = array_map(fn (array $bucket) => $bucket['end']->getTimestamp(), $buckets);

        return function (CarbonImmutable $moment) use ($starts, $ends): ?int {
            $time = $moment->getTimestamp();
            [$low, $high] = [0, count($starts) - 1];

            while ($low < $high) {
                $mid = intdiv($low + $high + 1, 2);
                $starts[$mid] <= $time ? $low = $mid : $high = $mid - 1;
            }

            return $starts[$low] <= $time && $time <= $ends[$low] ? $low : null;
        };
    }

    /** Income of the same length of time just before the period; for today, yesterday until the same time. */
    public function previousIncome(?CarbonImmutable $today = null): int
    {
        $today ??= CarbonImmutable::today();

        if ($this->period === 'today') {
            $yesterday = $today->subDay();
            $until = $yesterday->setTimeFrom(CarbonImmutable::now());

            return IncomeReport::paidBetween(Payment::query(), $yesterday, $yesterday)->get(['paid_on', 'amount_cents', 'currency', 'created_at'])
                ->filter(fn (Payment $payment) => self::paidAt($payment) <= $until)
                ->sum(fn (Payment $payment) => Currencies::toBase($payment->amount_cents, $payment->currency));
        }

        $buckets = $this->buckets(null, $today);
        $end = $buckets[0]['start']->subDay();
        $start = $this->period === '12m' ? $buckets[0]['start']->subYear() : $end->subDays(count($buckets) - 1);

        return array_sum(app(IncomeReport::class)->perDay($start, $end));
    }

    /**
     * When a payment came in: its paid_on date, at the hour it was recorded when that was the same day (paid_on has no
     * time), else the start of that day.
     */
    public static function paidAt(Payment $payment): CarbonImmutable
    {
        $day = CarbonImmutable::parse($payment->paid_on)->startOfDay();
        $recorded = $payment->created_at ? CarbonImmutable::parse($payment->created_at) : null;

        return $recorded && $recorded->isSameDay($day) ? $day->setTimeFrom($recorded) : $day;
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $buckets = $this->buckets();
        $series = $this->series();
        $income = array_sum(array_column($series, 'income'));
        $previous = $this->previousIncome();
        $currency = Currencies::base();

        // The line's scale: a round top above the highest bucket, four grid lines
        $top = max(1, max(array_column($series, 'income')));
        $step = 10 ** max(2, (int) floor(log10($top / 3)));
        $top = (int) (ceil($top / 3 / $step) * $step * 3);
        $count = max(1, max(array_merge(array_column($series, 'orders'), array_column($series, 'completed'))));

        $width = 600;
        $base = 178;
        $slot = $width / count($series);
        $bar = min(10, max(2, $slot / 3 - 1));
        $points = [];
        $bars = [];

        foreach ($series as $i => $bucket) {
            $x = $i * $slot + $slot / 2;
            // The line stops at the current hour: the hours to come have no income yet, not zero
            if (! ($buckets[$i]['future'] ?? false)) {
                $points[] = [round($x, 1), round($base - $bucket['income'] / $top * 168, 1)];
            }
            $bars[] = [
                'x1' => round($x - $bar - .5, 1), 'h1' => round($bucket['orders'] / $count * 120, 1),
                'x2' => round($x + .5, 1), 'h2' => round($bucket['completed'] / $count * 120, 1),
                'title' => __(':date: :orders new orders, :completed completed, :income', ['date' => $bucket['label'], 'orders' => $bucket['orders'], 'completed' => $bucket['completed'], 'income' => Money::format($bucket['income'], $currency)]),
            ];
        }

        $line = collect($points)->map(fn (array $p, int $i) => ($i ? 'L' : 'M').$p[0].' '.$p[1])->implode(' ');
        // About six labels at even steps (every 6 hours today), each under its own bar: counted back from today / this
        // month, on from 00:00 today
        $labelEvery = $this->period === 'today' ? 6 : max(1, (int) ceil(count($series) / 6));
        $anchor = $this->period === 'today' ? 0 : count($series) - 1;
        $labels = collect($series)
            ->filter(fn ($bucket, $i) => abs($anchor - $i) % $labelEvery === 0)
            ->map(function (array $bucket, int $i) use ($series) {
                $left = round(($i + .5) / count($series) * 100, 2);

                // Near an edge the label lines up with the edge instead, so it is not cut off
                return ['label' => $bucket['label'], 'style' => match (true) {
                    $left < 8 => 'left: 0; transform: none',
                    $left > 92 => 'right: 0; transform: none',
                    default => "left: {$left}%",
                }];
            })
            ->values()->all();

        return [
            'periods' => self::periodLabels(),
            'income' => Money::format($income, $currency),
            'previous' => Money::format($previous, $currency),
            'change' => $previous > 0 ? (int) round(($income - $previous) / $previous * 100) : null,
            'orders' => array_sum(array_column($series, 'orders')),
            'completed' => array_sum(array_column($series, 'completed')),
            'bars' => $bars,
            'bar' => $bar,
            'base' => $base,
            'line' => $line,
            'area' => $line.' L'.end($points)[0].' '.$base.' L'.$points[0][0].' '.$base.' Z',
            'axis' => $income === 0 ? [] : array_map(fn (int $i) => Money::format(intdiv($top * (3 - $i), 3), $currency), range(0, 3)),
            'labels' => $labels,
        ];
    }
}
