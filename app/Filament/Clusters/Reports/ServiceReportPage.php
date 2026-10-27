<?php

namespace App\Filament\Clusters\Reports;

use App\Enums\AdminArea;
use App\Filament\Concerns\LimitedToAdminArea;
use App\Filament\Concerns\TranslatesPageLabels;
use App\Filament\Resources\Services\ServiceResource;
use App\Models\Service;
use App\Providers\AppServiceProvider;
use App\Reports\Csv;
use App\Support\Money;
use BackedEnum;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

/**
 * A WHMCS-style custom report of services: pick the fields to include (the table's column toggle, some on by
 * default), narrow it with filters and date ranges, sort, and Export CSV gives exactly the rows and fields shown.
 * Each report lists its fields once in fields(); the table columns and the CSV are both built from it.
 *
 * @phpstan-type Field array{label: string, value: Closure(Service): (string|int|null), default?: bool, column?: Closure(TextColumn): TextColumn}
 */
abstract class ServiceReportPage extends Page implements HasTable, ReportCard
{
    use InteractsWithTable;
    use LimitedToAdminArea;
    use TranslatesPageLabels;

    protected static ?string $cluster = ReportsCluster::class;

    protected static string|UnitEnum|null $navigationGroup = 'Services';

    /**
     * The fields to choose from, by column name (an attribute or relation.attribute, so it sorts and searches).
     *
     * @return array<string, Field>
     */
    abstract protected function fields(): array;

    /** @return Builder<Service> */
    abstract protected function query(): Builder;

    /** Filters next to the date ranges. */
    abstract protected function filters(): array;

    /** @return array<string, string> column => label of the date ranges offered as filters */
    abstract protected function dateRanges(): array;

    abstract protected function csvName(): string;

    public static function adminArea(): ?AdminArea
    {
        return AdminArea::Reports;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query($this->query())
            ->columns(collect($this->fields())->map(function (array $field, string $name) {
                $column = TextColumn::make($name)
                    ->label($field['label'])
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: ! ($field['default'] ?? false));

                return isset($field['column']) ? $field['column']($column) : $column->state($field['value']);
            })->values()->all())
            ->filters([
                ...$this->filters(),
                ...collect($this->dateRanges())->map(fn (string $label, string $column) => $this->dateRange($column, $label))->values()->all(),
            ], layout: FiltersLayout::AboveContentCollapsible)
            ->filtersFormColumns(4)
            ->deferFilters(false)
            ->columnManagerColumns(3)
            ->searchable()
            ->defaultSort('next_due_date')
            ->recordUrl(fn (Service $record) => ServiceResource::getUrl('edit', ['record' => $record]))
            ->paginated([25, 50, 100, 'all'])
            ->defaultPaginationPageOption(50)
            ->emptyStateHeading(__('Nothing matches these filters'));
    }

    /** A "from / until" filter on a date column (relation.column for a related one). */
    private function dateRange(string $column, string $label): Filter
    {
        $apply = function (Builder $query, ?string $from, ?string $until) use ($column) {
            [$relation, $attribute] = str_contains($column, '.') ? explode('.', $column, 2) : [null, $column];
            $where = fn (Builder $q) => $q
                ->when($from, fn (Builder $q, string $date) => $q->whereDate($attribute, '>=', $date))
                ->when($until, fn (Builder $q, string $date) => $q->whereDate($attribute, '<=', $date));

            return $relation ? $query->whereHas($relation, $where) : $where($query);
        };

        return Filter::make(str_replace('.', '_', $column).'_range')
            ->label($label)
            ->schema([
                DatePicker::make('from')->label(__(':field from', ['field' => $label])),
                DatePicker::make('until')->label(__(':field until', ['field' => $label])),
            ])
            ->columnSpan(2)
            ->columns(2)
            ->query(fn (Builder $query, array $data) => ($data['from'] ?? null) || ($data['until'] ?? null) ? $apply($query, $data['from'] ?? null, $data['until'] ?? null) : $query)
            ->indicateUsing(function (array $data) use ($label) {
                if (! ($data['from'] ?? null) && ! ($data['until'] ?? null)) {
                    return null;
                }

                return $label.': '.collect([$data['from'] ?? null, $data['until'] ?? null])
                    ->map(fn (?string $d) => $d ? CarbonImmutable::parse($d)->format(AppServiceProvider::dateFormat()) : '…')
                    ->implode(' – ');
            });
    }

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

    /** The rows that match the filters and search, in the table's order, with the fields shown. */
    public function csv(): StreamedResponse
    {
        $fields = array_intersect_key($this->fields(), $this->getTable()->getVisibleColumns());
        $query = $this->getFilteredSortedTableQuery();

        return Csv::download(
            $this->csvName(),
            array_column($fields, 'label'),
            (function () use ($query, $fields) {
                foreach ($query->lazy(200) as $service) {
                    yield array_map(fn (array $field) => $field['value']($service), $fields);
                }
            })(),
        );
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    /* Field values, as text for the CSV (and the table where no column is given). */

    protected static function money(?int $cents): ?string
    {
        return $cents === null ? null : Money::toDecimal($cents);
    }

    protected static function date(?CarbonInterface $date): ?string
    {
        return $date?->format(AppServiceProvider::dateFormat());
    }

    protected static function label(?BackedEnum $value): ?string
    {
        return $value instanceof HasLabel ? $value->getLabel() : $value?->value;
    }

    protected static function yesNo(bool $value): string
    {
        return $value ? __('Yes') : __('No');
    }

    /** A money column that shows the amount in the service's currency and sorts on its cents. */
    protected static function moneyColumn(Closure $cents): Closure
    {
        return fn (TextColumn $column) => $column
            ->state($cents)
            ->formatStateUsing(fn (?int $state, Service $record) => $state === null ? null : Money::format($state, $record->currency))
            ->alignEnd();
    }
}
