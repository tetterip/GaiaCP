<?php

namespace App\Filament\Resources\Clients\Tables;

use App\Enums\ClientStatus;
use App\Models\Client;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ClientsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('last_name')
                    ->label('Name')
                    ->formatStateUsing(fn (Client $record) => $record->name)
                    ->description(fn (Client $record) => $record->company)
                    ->searchable(['first_name', 'last_name', 'company'])
                    ->sortable(['last_name', 'first_name']),
                TextColumn::make('email')
                    ->searchable()
                    ->copyable(),
                TextColumn::make('phone')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('vat_number')
                    ->label('VAT number')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('services_count')
                    ->counts('services')
                    ->label('Services')
                    ->sortable(),
                TextColumn::make('status')
                    ->badge(),
                TextColumn::make('created_at')
                    ->date()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort(fn (Builder $query) => $query->orderBy('last_name')->orderBy('first_name'))
            ->filters([
                SelectFilter::make('status')
                    ->options(ClientStatus::class),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
