<?php

namespace App\Filament\Clusters\Reports\Pages;

use App\Filament\Clusters\Reports\IncomeReportPage;
use App\Reports\IncomeReport;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;

/** Payments received in a year, month by month, next to the same month of the year before. */
class AnnualIncome extends IncomeReportPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendar;

    protected static ?int $navigationSort = 1;

    protected static ?string $title = 'Annual income';

    protected static ?string $slug = 'annual-income';

    #[Url]
    public int $year = 0;

    public function mount(): void
    {
        $this->year = in_array($this->year, $this->years(), true) ? $this->year : today()->year;
    }

    public static function reportDescription(): string
    {
        return __('Payments received each month of a year, compared with the year before.');
    }

    /** @return list<int> */
    public function years(): array
    {
        return app(IncomeReport::class)->years();
    }

    public function controls(): ?string
    {
        return 'filament.reports.year';
    }

    public function note(): ?string
    {
        return __('Payments as recorded, tax included when the payment notice charged it.');
    }

    protected function csvName(): string
    {
        return "income-{$this->year}.csv";
    }

    public function report(): array
    {
        $income = app(IncomeReport::class);
        $current = $income->monthly($this->year);
        $previous = $income->monthly($this->year - 1);
        $max = max(array_column($current, 'cents'));
        $rows = $csv = [];

        foreach ($current as $month => $row) {
            $before = $previous[$month]['cents'];
            $name = ucfirst(CarbonImmutable::create($this->year, $month)->locale(app()->getLocale())->isoFormat('MMMM'));
            $future = CarbonImmutable::create($this->year, $month)->isAfter(today());

            $rows[] = [
                'cells' => [$name, (string) $row['count'], static::bar($row['cents'], $max), static::money($before), $this->change($row['cents'], $before)],
                'muted' => $future,
            ];
            $csv[] = [$name, $row['count'], static::decimal($row['cents']), static::decimal($before), $this->change($row['cents'], $before)];
        }

        $total = array_sum(array_column($current, 'cents'));
        $totalBefore = array_sum(array_column($previous, 'cents'));
        $count = array_sum(array_column($current, 'count'));
        $csv[] = [__('Total'), $count, static::decimal($total), static::decimal($totalBefore), $this->change($total, $totalBefore)];

        return [
            'columns' => [
                ['label' => __('Month')],
                ['label' => __('Payments'), 'end' => true],
                ['label' => (string) $this->year, 'end' => true],
                ['label' => (string) ($this->year - 1), 'end' => true],
                ['label' => __('Change'), 'end' => true],
            ],
            'rows' => $rows,
            'totals' => [__('Total'), (string) $count, static::money($total), static::money($totalBefore), $this->change($total, $totalBefore)],
            'csv' => $csv,
        ];
    }

    /** "+12%", "−5%", or "—" when there is nothing to compare with. */
    private function change(int $now, int $before): string
    {
        if ($before <= 0) {
            return '—';
        }

        $percent = (int) round(($now - $before) / $before * 100);

        return ($percent > 0 ? '+' : ($percent < 0 ? '−' : '')).abs($percent).'%';
    }
}
