<?php

namespace App\Filament\Clusters\Reports\Pages;

use App\Filament\Clusters\Reports\IncomeReportPage;
use App\Reports\IncomeReport;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;
use Throwable;

/** Payments received in a period by product (services without a product by their type). */
class IncomeByProduct extends IncomeReportPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCube;

    protected static ?int $navigationSort = 3;

    protected static ?string $title = 'Income by product';

    protected static ?string $slug = 'income-by-product';

    /** Y-m-d */
    #[Url]
    public string $from = '';

    /** Y-m-d */
    #[Url]
    public string $until = '';

    public function mount(): void
    {
        if (! $this->date($this->from) || ! $this->date($this->until)) {
            $this->preset('this_year');
        }
    }

    public static function reportDescription(): string
    {
        return __('Payments received in a period for each product, largest first.');
    }

    /** @return array<string, string> */
    public function presets(): array
    {
        return [
            'this_month' => __('This month'),
            'last_month' => __('Last month'),
            'this_year' => __('This year'),
            'last_year' => __('Last year'),
        ];
    }

    public function preset(string $preset): void
    {
        $today = today()->toImmutable();

        [$from, $until] = match ($preset) {
            'this_month' => [$today->startOfMonth(), $today->endOfMonth()],
            'last_month' => [$today->subMonthNoOverflow()->startOfMonth(), $today->subMonthNoOverflow()->endOfMonth()],
            'last_year' => [$today->subYear()->startOfYear(), $today->subYear()->endOfYear()],
            default => [$today->startOfYear(), $today->endOfYear()],
        };

        $this->from = $from->toDateString();
        $this->until = $until->toDateString();
    }

    private function date(string $value): ?CarbonImmutable
    {
        try {
            return $value === '' ? null : CarbonImmutable::createFromFormat('!Y-m-d', $value);
        } catch (Throwable) {
            return null;
        }
    }

    public function controls(): ?string
    {
        return 'filament.reports.period';
    }

    public function note(): ?string
    {
        return __('Payments as recorded, tax included when the payment notice charged it.');
    }

    protected function csvName(): string
    {
        return "income-by-product-{$this->from}-{$this->until}.csv";
    }

    public function report(): array
    {
        $from = $this->date($this->from) ?? today()->startOfYear()->toImmutable();
        $until = $this->date($this->until) ?? today()->endOfYear()->toImmutable();
        $products = app(IncomeReport::class)->byProduct($from, $until);
        $total = (int) $products->sum('cents');
        $max = (int) $products->max('cents');
        $share = fn (int $cents) => $total > 0 ? round($cents / $total * 100).'%' : '—';

        return [
            'columns' => [
                ['label' => __('Product')],
                ['label' => __('Type')],
                ['label' => __('Payments'), 'end' => true],
                ['label' => __('Share'), 'end' => true],
                ['label' => __('Amount'), 'end' => true],
            ],
            'rows' => $products->map(fn (array $p) => [
                'cells' => [$p['product'], $p['type'], (string) $p['count'], $share($p['cents']), static::bar($p['cents'], $max)],
            ])->all(),
            'totals' => [__('Total'), '', (string) $products->sum('count'), $total > 0 ? '100%' : '—', static::money($total)],
            'empty' => __('No payments were recorded in this period.'),
            'csv' => $products->map(fn (array $p) => [$p['product'], $p['type'], $p['count'], $share($p['cents']), static::decimal($p['cents'])])->all(),
        ];
    }
}
