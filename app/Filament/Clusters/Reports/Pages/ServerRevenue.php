<?php

namespace App\Filament\Clusters\Reports\Pages;

use App\Filament\Clusters\Reports\IncomeReportPage;
use App\Reports\IncomeForecast as Forecast;
use BackedEnum;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;

/** Hosting revenue per server: accounts, recurring value and the renewals due ahead. */
class ServerRevenue extends IncomeReportPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedServerStack;

    protected static ?int $navigationSort = 5;

    protected static ?string $title = 'Server revenue';

    protected static ?string $slug = 'server-revenue';

    #[Url]
    public int $months = 12;

    public function mount(): void
    {
        $this->months = in_array($this->months, [6, 12, 24, 36], true) ? $this->months : 12;
    }

    public static function reportDescription(): string
    {
        return __('Hosting accounts on each server, what they bring in a month and a year, and the renewals due ahead.');
    }

    public function controls(): ?string
    {
        return 'filament.reports.horizon';
    }

    public function note(): ?string
    {
        $note = __('Active and suspended hosting accounts that renew, at their prices (before tax when tax is added on top). Per month and per year spread each price over its billing cycle.');

        return (new Forecast)->serverCosts() > 0
            ? $note.' '.__('Cost = the server\'s monthly cost; profit = per month minus it.')
            : $note;
    }

    protected function csvName(): string
    {
        return 'server-revenue.csv';
    }

    public function report(): array
    {
        $servers = (new Forecast)->byServer(in_array($this->months, [6, 12, 24, 36], true) ? $this->months : 12);
        $max = (int) $servers->max('yearly_cents');
        $ahead = __('Next :count months', ['count' => $this->months]);
        // Cost and profit only once a server has a Monthly cost (Settings → Servers).
        $costs = $servers->contains(fn (array $s) => $s['cost_cents'] !== null);
        $profit = fn (array $s) => $s['monthly_cents'] - (int) $s['cost_cents'];
        $totalCost = (int) $servers->sum('cost_cents');
        $totalProfit = (int) $servers->sum('monthly_cents') - $totalCost;

        return [
            'columns' => [
                ['label' => __('Server')],
                ['label' => __('Accounts'), 'end' => true],
                ['label' => __('Per month'), 'end' => true],
                ['label' => __('Per year'), 'end' => true],
                ['label' => $ahead, 'end' => true],
                ...($costs ? [['label' => __('Cost'), 'end' => true], ['label' => __('Profit'), 'end' => true]] : []),
            ],
            'rows' => $servers->map(fn (array $s) => [
                'cells' => [
                    $s['server'], (string) $s['accounts'], static::money($s['monthly_cents']), static::bar($s['yearly_cents'], $max), static::money($s['forecast_cents']),
                    ...($costs ? [$s['cost_cents'] === null ? '' : static::money($s['cost_cents']), static::money($profit($s))] : []),
                ],
            ])->all(),
            'totals' => [
                __('Total'), (string) $servers->sum('accounts'), static::money((int) $servers->sum('monthly_cents')), static::money((int) $servers->sum('yearly_cents')), static::money((int) $servers->sum('forecast_cents')),
                ...($costs ? [static::money($totalCost), static::money($totalProfit)] : []),
            ],
            'empty' => __('No active hosting accounts on a server.'),
            'csv' => $servers->map(fn (array $s) => [
                $s['server'], $s['accounts'], static::decimal($s['monthly_cents']), static::decimal($s['yearly_cents']), static::decimal($s['forecast_cents']),
                ...($costs ? [$s['cost_cents'] === null ? null : static::decimal($s['cost_cents']), static::decimal($profit($s))] : []),
            ])->all(),
        ];
    }
}
