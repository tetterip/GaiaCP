<?php

namespace App\Filament\Clusters\Reports\Pages;

use App\Filament\Clusters\Reports\IncomeReportPage;
use App\Reports\IncomeForecast as Forecast;
use BackedEnum;
use Filament\Support\Icons\Heroicon;

/** Domains per TLD: what they bring in a year, what the registrars charge to renew them, and the profit. */
class DomainProfit extends IncomeReportPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGlobeAlt;

    protected static ?int $navigationSort = 6;

    protected static ?string $title = 'Domain profit';

    protected static ?string $slug = 'domain-profit';

    public static function reportDescription(): string
    {
        return __('Domains per TLD: what they bring in a year, what the registrar charges to renew them, and the profit.');
    }

    public function note(): ?string
    {
        return __('Active and suspended domains that renew, at their prices (before tax when tax is added on top), each spread over its term. Cost = the TLD\'s renew cost under Settings → Domain pricing → Registrar cost; domains without one count as no cost. Domains registered elsewhere are left out.');
    }

    protected function csvName(): string
    {
        return 'domain-profit.csv';
    }

    public function report(): array
    {
        $tlds = (new Forecast)->byTld();
        $max = (int) $tlds->max('yearly_cents');
        $profit = fn (array $t) => $t['yearly_cents'] - $t['yearly_cost_cents'];
        $margin = fn (int $profit, int $revenue) => $revenue > 0 ? round($profit * 100 / $revenue).'%' : '';
        $totalRevenue = (int) $tlds->sum('yearly_cents');
        $totalProfit = $totalRevenue - (int) $tlds->sum('yearly_cost_cents');

        return [
            'columns' => [
                ['label' => __('TLD')],
                ['label' => __('Domains'), 'end' => true],
                ['label' => __('Per year'), 'end' => true],
                ['label' => __('Cost per year'), 'end' => true],
                ['label' => __('Profit per year'), 'end' => true],
                ['label' => __('Margin'), 'end' => true],
                ['label' => __('Without a cost'), 'end' => true],
            ],
            'rows' => $tlds->map(fn (array $t) => [
                'cells' => [
                    $t['tld'], (string) $t['domains'], static::bar($t['yearly_cents'], $max), static::money($t['yearly_cost_cents']),
                    static::money($profit($t)), $margin($profit($t), $t['yearly_cents']), $t['without_cost'] ? (string) $t['without_cost'] : '',
                ],
            ])->all(),
            'totals' => [
                __('Total'), (string) $tlds->sum('domains'), static::money($totalRevenue), static::money((int) $tlds->sum('yearly_cost_cents')),
                static::money($totalProfit), $margin($totalProfit, $totalRevenue), (string) ($tlds->sum('without_cost') ?: ''),
            ],
            'empty' => __('No active domains that renew.'),
            'csv' => $tlds->map(fn (array $t) => [
                $t['tld'], $t['domains'], static::decimal($t['yearly_cents']), static::decimal($t['yearly_cost_cents']),
                static::decimal($profit($t)), $margin($profit($t), $t['yearly_cents']), $t['without_cost'],
            ])->all(),
        ];
    }
}
