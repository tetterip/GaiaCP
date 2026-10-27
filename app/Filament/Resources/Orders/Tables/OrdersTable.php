<?php

namespace App\Filament\Resources\Orders\Tables;

use App\Enums\OrderStatus;
use App\Filament\Resources\Orders\OrderResource;
use App\Models\Order;
use App\Support\Money;
use App\Support\Tax;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class OrdersTable
{
    public static function configure(Table $table, bool $showClient = true): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('client', 'services', 'invoices'))
            ->columns([
                TextColumn::make('id')
                    ->label('#')
                    ->sortable(),
                TextColumn::make('client.last_name')
                    ->label('Client')
                    ->formatStateUsing(fn (Order $record) => $record->client->name)
                    ->description(fn (Order $record) => $record->client->company)
                    ->searchable(['first_name', 'last_name', 'company'])
                    ->visible($showClient),
                TextColumn::make('services.label')
                    ->label('Items')
                    ->listWithLineBreaks()
                    ->wrap()
                    ->limitList(4),
                TextColumn::make('total')
                    ->state(fn (Order $record) => Money::format($record->totals()['total'], $record->currency()))
                    ->description(fn (Order $record) => ($totals = $record->totals())['rule']
                        ? __(':net + :tax :tax_amount', ['net' => Money::format($totals['net'], $record->currency()), 'tax' => $totals['rule']['name'].' '.Tax::formatRate($totals['rule']['rate']), 'tax_amount' => Money::format($totals['tax'], $record->currency())])
                        : null),
                TextColumn::make('status')
                    ->badge(),
                TextColumn::make('created_at')
                    ->label('Placed')
                    ->date()
                    ->sortable(),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options(OrderStatus::class),
            ])
            // The chips (Orders\Concerns\HasStatusChips) set the filter and show which one is on, so no filter button
            ->filtersLayout(FiltersLayout::Hidden)
            ->recordUrl(fn (Order $record) => OrderResource::getUrl('view', ['record' => $record]));
    }
}
