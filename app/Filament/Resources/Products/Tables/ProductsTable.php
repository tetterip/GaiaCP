<?php

namespace App\Filament\Resources\Products\Tables;

use App\Filament\Actions\ChangePriceAction;
use App\Models\Product;
use App\Support\Money;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class ProductsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('group'))
            ->columns([
                TextColumn::make('name')
                    ->description(fn (Product $record) => $record->package)
                    ->searchable()
                    ->sortable(),
                TextColumn::make('type')
                    ->badge(),
                TextColumn::make('group')
                    ->state(fn (Product $record) => $record->group?->name())
                    ->placeholder(__('Other'))
                    ->toggleable(),
                TextColumn::make('prices')
                    ->label('Prices')
                    ->state(fn (Product $record) => collect($record->cycles())
                        ->map(fn ($cycle) => $cycle->getLabel().': '.Money::format($record->priceFor($cycle), $record->currency)
                            .($record->setupFeeFor($cycle) > 0 ? ' + '.__(':amount setup', ['amount' => Money::format($record->setupFeeFor($cycle), $record->currency)]) : ''))
                        ->all())
                    ->listWithLineBreaks()
                    ->placeholder(__('No prices')),
                TextColumn::make('services_count')
                    ->counts('services')
                    ->label('Services')
                    ->sortable(),
                IconColumn::make('hidden')
                    ->boolean()
                    ->toggleable(),
                IconColumn::make('retired')
                    ->boolean()
                    ->toggleable(),
            ])
            ->defaultSort('name')
            ->filters([
                TernaryFilter::make('retired')
                    ->default(false),
            ])
            ->recordActions([
                ActionGroup::make([
                    EditAction::make(),
                    ChangePriceAction::forProduct(),
                ]),
            ]);
    }
}
