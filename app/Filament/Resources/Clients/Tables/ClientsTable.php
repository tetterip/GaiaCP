<?php

namespace App\Filament\Resources\Clients\Tables;

use App\Enums\ClientAreaLogin;
use App\Enums\ClientStatus;
use App\Enums\ServiceStatus;
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
            ->modifyQueryUsing(fn (Builder $query) => $query->with('user'))
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
                    ->placeholder('—') // the row's link in an empty cell still needs a text (WCAG 2.4.4)
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('active_services_count')
                    ->counts(['services as active_services_count' => fn (Builder $query) => $query->where('status', ServiceStatus::Active)])
                    ->label('Active services')
                    ->sortable(),
                TextColumn::make('status')
                    ->badge(),
                TextColumn::make('client_area_login')
                    ->label('Client area')
                    ->state(fn (Client $record) => ClientAreaLogin::for($record))
                    ->badge()
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->date()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort(fn (Builder $query) => $query->orderBy('last_name')->orderBy('first_name'))
            ->filters([
                SelectFilter::make('status')
                    ->options(ClientStatus::class),
                SelectFilter::make('client_area_login')
                    ->label('Client area')
                    ->options(ClientAreaLogin::class)
                    ->query(fn (Builder $query, array $data) => filled($data['value'] ?? null)
                        ? ClientAreaLogin::from($data['value'])->apply($query)
                        : null),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([ClientBulkActions::group()])
            ->maxSelectableRecords(ClientBulkActions::MAX);
    }
}
