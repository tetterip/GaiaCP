<?php

namespace App\Filament\Clusters\Reports\Pages;

use App\Enums\ServiceType;
use App\Filament\Clusters\Reports\IncomeReportPage;
use App\Reports\IncomeForecast as Forecast;
use BackedEnum;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;

/** The renewals still to be paid, month by month, split by service type. */
class IncomeForecast extends IncomeReportPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowTrendingUp;

    protected static ?int $navigationSort = 4;

    protected static ?string $title = 'Income forecast';

    protected static ?string $slug = 'income-forecast';

    #[Url]
    public int $months = 12;

    public function mount(): void
    {
        $this->months = in_array($this->months, [6, 12, 24, 36], true) ? $this->months : 12;
    }

    public static function reportDescription(): string
    {
        return __('What active services will bring in each month if every renewal is paid.');
    }

    public function controls(): ?string
    {
        return 'filament.reports.horizon';
    }

    public function note(): ?string
    {
        $forecast = new Forecast;
        $overdue = $forecast->overdue();

        $note = __('At the services\' prices (before tax when tax is added on top). Services set to auto-terminate are left out. Overdue now and not in the table: :amount for :count renewal(s).', [
            'amount' => static::money($overdue['cents']),
            'count' => $overdue['count'],
        ]);

        if ($forecast->serverCosts() > 0) {
            $note .= ' '.__('Profit = the month\'s renewals minus :cost, what the active servers cost a month.', ['cost' => static::money($forecast->serverCosts())]);
        }

        return array_sum(array_column($forecast->monthly($this->months), 'domain_costs')) > 0
            ? $note.' '.__('Domain costs = what the registrars charge to renew the month\'s domains (Settings → Domain pricing → Registrar cost); they are taken off the profit too.')
            : $note;
    }

    protected function csvName(): string
    {
        return "income-forecast-{$this->months}-months.csv";
    }

    public function report(): array
    {
        $forecast = new Forecast;
        $months = $forecast->monthly(in_array($this->months, [6, 12, 24, 36], true) ? $this->months : 12);
        // Profit once a server has a Monthly cost: each month's renewals minus what the active servers cost.
        $cost = $forecast->serverCosts();
        // And the registrars' renewal costs of the month's domains, once a TLD has a Registrar cost.
        $domainCosts = array_sum(array_column($months, 'domain_costs')) > 0;
        $withProfit = $cost > 0 || $domainCosts;
        // Only the types with something due, so the table stays narrow.
        $types = array_values(array_filter(ServiceType::cases(), fn (ServiceType $t) => array_sum(array_map(fn (array $m) => $m['types'][$t->value], $months)) > 0));
        $max = max(array_column($months, 'cents'));
        $rows = $csv = [];

        foreach ($months as $month) {
            $name = ucfirst($month['month']->locale(app()->getLocale())->isoFormat('MMMM YYYY'));
            $byType = array_map(fn (ServiceType $t) => $month['types'][$t->value], $types);

            $expenses = [...($domainCosts ? [$month['domain_costs']] : []), ...($withProfit ? [$month['cents'] - $cost - $month['domain_costs']] : [])];

            $rows[] = ['cells' => [$name, (string) $month['count'], ...array_map(static::money(...), $byType), static::bar($month['cents'], $max), ...array_map(static::money(...), $expenses)]];
            $csv[] = [$name, $month['count'], ...array_map(static::decimal(...), $byType), static::decimal($month['cents']), ...array_map(static::decimal(...), $expenses)];
        }

        $typeTotals = array_map(fn (ServiceType $t) => array_sum(array_map(fn (array $m) => $m['types'][$t->value], $months)), $types);
        $total = array_sum(array_column($months, 'cents'));
        $count = array_sum(array_column($months, 'count'));
        $totalDomainCosts = array_sum(array_column($months, 'domain_costs'));
        $totalProfit = [...($domainCosts ? [$totalDomainCosts] : []), ...($withProfit ? [$total - $cost * count($months) - $totalDomainCosts] : [])];
        $csv[] = [__('Total'), $count, ...array_map(static::decimal(...), $typeTotals), static::decimal($total), ...array_map(static::decimal(...), $totalProfit)];

        return [
            'columns' => [
                ['label' => __('Month')],
                ['label' => __('Renewals'), 'end' => true],
                ...array_map(fn (ServiceType $t) => ['label' => $t->getLabel(), 'end' => true], $types),
                ['label' => __('Total'), 'end' => true],
                ...($domainCosts ? [['label' => __('Domain costs'), 'end' => true]] : []),
                ...($withProfit ? [['label' => __('Profit'), 'end' => true]] : []),
            ],
            'rows' => $rows,
            'totals' => [__('Total'), (string) $count, ...array_map(static::money(...), $typeTotals), static::money($total), ...array_map(static::money(...), $totalProfit)],
            'csv' => $csv,
        ];
    }
}
