<?php

namespace App\Filament\Resources\Invoices\Tables;

use App\Enums\InvoiceStatus;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Models\Invoice;
use App\Support\Currencies;
use App\Support\Money;
use Filament\Tables\Columns\Summarizers\Summarizer;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

class InvoicesTable
{
    public static function configure(Table $table, bool $showClient = true): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('client', 'items'))
            ->columns(array_values(array_filter([
                TextColumn::make('number')
                    ->searchable()
                    ->sortable(),
                $showClient ? TextColumn::make('client.last_name')
                    ->label('Client')
                    ->formatStateUsing(fn (Invoice $record) => $record->client->name)
                    ->description(fn (Invoice $record) => $record->client->company)
                    ->searchable(['first_name', 'last_name', 'company']) : null,
                TextColumn::make('items.description')
                    ->label('Items')
                    ->listWithLineBreaks()
                    ->wrap()
                    ->limitList(3),
                TextColumn::make('date')
                    ->date()
                    ->sortable(),
                TextColumn::make('due_date')
                    ->label('Due')
                    ->date()
                    ->sortable()
                    ->color(fn (Invoice $record) => $record->isOverdue() ? 'danger' : null)
                    ->weight(fn (Invoice $record) => $record->isOverdue() ? 'bold' : null),
                TextColumn::make('total_cents')
                    ->label('Total')
                    ->formatStateUsing(fn (Invoice $record) => Money::format($record->total_cents, $record->currency))
                    ->sortable()
                    // Every currency, in the base one at today's rate.
                    ->summarize(Summarizer::make()->label('Total')->using(fn (QueryBuilder $query) => Currencies::sumToBase($query, 'total_cents'))->formatStateUsing(fn ($state) => Money::format((int) $state))),
                TextColumn::make('status')
                    ->badge(),
                TextColumn::make('paid_on')
                    ->date()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])))
            // Newest first; the chips over the table (HasStatusChips) narrow it to a status or the overdue ones
            ->defaultSort(fn (Builder $query) => $query->orderByDesc('date')->orderByDesc('id'))
            ->filters([
                SelectFilter::make('status')
                    ->options(InvoiceStatus::class),
                Filter::make('overdue')
                    ->query(fn (Builder $query) => $query->unpaid()->whereDate('due_date', '<', today())),
            ])
            // The chips set these filters and show which one is on, so no filter button or indicator row
            ->filtersLayout(FiltersLayout::Hidden)
            ->hiddenFilterIndicators()
            ->recordUrl(fn (Invoice $record) => InvoiceResource::getUrl('view', ['record' => $record]));
    }
}
