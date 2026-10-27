<?php

namespace App\Filament\Clusters\Reports\Pages;

use App\Filament\Clusters\Reports\IncomeReportPage;
use App\Models\Payment;
use App\Payments\PaymentMethods;
use App\Providers\AppServiceProvider;
use App\Reports\Csv;
use App\Reports\IncomeReport;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** One month's payments: a line per day, and every payment under it. */
class MonthlyTransactions extends IncomeReportPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?int $navigationSort = 2;

    protected static ?string $title = 'Monthly transactions';

    protected static ?string $slug = 'monthly-transactions';

    /** Y-m */
    #[Url]
    public string $month = '';

    public function mount(): void
    {
        $this->month = array_key_exists($this->month, $this->months()) ? $this->month : today()->format('Y-m');
    }

    public static function reportDescription(): string
    {
        return __('Payments received in a month, day by day, with each payment listed.');
    }

    /** @return array<string, string> Y-m => "October 2026", newest first, back to the first payment */
    public function months(): array
    {
        $first = CarbonImmutable::create(min(app(IncomeReport::class)->years()));
        $months = [];

        for ($m = today()->startOfMonth()->toImmutable(); $m->greaterThanOrEqualTo($first); $m = $m->subMonth()) {
            $months[$m->format('Y-m')] = ucfirst($m->locale(app()->getLocale())->isoFormat('MMMM YYYY'));
        }

        return $months;
    }

    private function start(): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m', $this->month);
    }

    public function controls(): ?string
    {
        return 'filament.reports.month';
    }

    public function after(): ?string
    {
        return 'filament.reports.payments';
    }

    /** @return Collection<int, Payment> */
    public function payments(): Collection
    {
        return app(IncomeReport::class)->between($this->start(), $this->start()->endOfMonth());
    }

    protected function csvName(): string
    {
        return "transactions-{$this->month}.csv";
    }

    public function report(): array
    {
        $days = app(IncomeReport::class)->daily($this->start()->year, $this->start()->month);
        $max = max(array_column($days, 'cents'));
        $rows = [];

        foreach ($days as $day => $row) {
            if ($row['count'] > 0) {
                $rows[] = ['cells' => [$this->start()->setDay($day)->format(AppServiceProvider::dateFormat()), (string) $row['count'], static::bar($row['cents'], $max)]];
            }
        }

        return [
            'columns' => [
                ['label' => __('Date')],
                ['label' => __('Payments'), 'end' => true],
                ['label' => __('Amount'), 'end' => true],
            ],
            'rows' => $rows,
            'totals' => [__('Total'), (string) array_sum(array_column($days, 'count')), static::money(array_sum(array_column($days, 'cents')))],
            'empty' => __('No payments were recorded this month.'),
            'csv' => [],
        ];
    }

    /** The CSV lists every payment of the month, not the days. */
    public function csv(): StreamedResponse
    {
        return Csv::download($this->csvName(),
            [__('Date'), __('Client'), __('Service'), __('Domain'), __('Method'), __('Reference'), __('Amount')],
            $this->payments()->map(fn (Payment $p) => [
                $p->paid_on->format(AppServiceProvider::dateFormat()),
                ($p->client ?? $p->service->client)->name,
                $p->service->label,
                $p->service->domain,
                PaymentMethods::label($p->method),
                $p->reference,
                static::decimal($p->amount_cents),
            ]));
    }
}
