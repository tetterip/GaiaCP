<?php

namespace App\Filament\Clusters\Reports;

use App\Enums\AdminArea;
use App\Filament\Concerns\LimitedToAdminArea;
use App\Filament\Concerns\TranslatesPageLabels;
use App\Reports\Csv;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

/**
 * An income report: a table built by report() (columns, rows, totals) under the page's own controls, shown by
 * resources/views/filament/reports/table.blade.php, with Export CSV in the header.
 *
 * @phpstan-type Cell string|array{text: string, bar: float}
 * @phpstan-type ReportTable array{columns: list<array{label: string, end?: bool}>, rows: list<array{cells: list<Cell>, muted?: bool}>, totals?: list<string>|null, csv: list<list<scalar|null>>, empty?: string}
 */
abstract class IncomeReportPage extends Page implements ReportCard
{
    use LimitedToAdminArea;
    use TranslatesPageLabels;

    protected static ?string $cluster = ReportsCluster::class;

    protected static string|UnitEnum|null $navigationGroup = 'Income';

    protected string $view = 'filament.reports.table';

    /** @return ReportTable */
    abstract public function report(): array;

    public static function adminArea(): ?AdminArea
    {
        return AdminArea::Reports;
    }

    /** View with the page's own inputs (year, month, period), shown above the table; null for none. */
    public function controls(): ?string
    {
        return null;
    }

    /** One line under the table: how the numbers are made. */
    public function note(): ?string
    {
        return null;
    }

    /** View shown under the table (e.g. the payments behind it); null for none. */
    public function after(): ?string
    {
        return null;
    }

    abstract protected function csvName(): string;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('exportCsv')
                ->label('Export CSV')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->action(fn (): StreamedResponse => $this->csv()),
        ];
    }

    public function csv(): StreamedResponse
    {
        $table = $this->report();

        return Csv::download($this->csvName(), array_column($table['columns'], 'label'), $table['csv']);
    }

    protected static function money(int $cents): string
    {
        return Money::format($cents, config('gaiacp.currency'));
    }

    /** Cents as a CSV value: "12.50". */
    protected static function decimal(int $cents): string
    {
        return Money::toDecimal($cents);
    }

    /** A cell with a bar sized against the largest value in the column. */
    protected static function bar(int $cents, int $max): array
    {
        return ['text' => static::money($cents), 'bar' => $max > 0 ? max(0, $cents) / $max : 0.0];
    }
}
