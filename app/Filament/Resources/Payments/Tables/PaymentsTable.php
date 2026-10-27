<?php

namespace App\Filament\Resources\Payments\Tables;

use App\Filament\Resources\Services\ServiceResource;
use App\Models\Payment;
use App\Payments\PaymentMethods;
use App\Support\Currencies;
use App\Support\Money;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\Summarizers\Summarizer;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

class PaymentsTable
{
    public static function configure(Table $table, bool $showClient = true): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('service.client'))
            ->columns([
                TextColumn::make('paid_on')
                    ->date()
                    ->sortable(),
                TextColumn::make('service.client.last_name')
                    ->label('Client')
                    ->formatStateUsing(fn (Payment $record) => $record->service->client->name)
                    ->searchable(['first_name', 'last_name', 'company'])
                    ->visible($showClient),
                TextColumn::make('service.label')
                    ->label('Service')
                    ->description(fn (Payment $record) => $record->service->domain)
                    ->searchable(['label', 'domain']),
                TextColumn::make('amount_cents')
                    ->label('Amount')
                    ->formatStateUsing(fn (Payment $record) => Money::format($record->amount_cents, $record->currency))
                    ->sortable()
                    // Every currency, in the base one at today's rate.
                    ->summarize(Summarizer::make()->label('Total')->using(fn (QueryBuilder $query) => Currencies::sumToBase($query, 'amount_cents'))->formatStateUsing(fn ($state) => Money::format((int) $state))),
                TextColumn::make('method')
                    ->formatStateUsing(fn (?string $state) => PaymentMethods::label($state))
                    ->badge(),
                TextColumn::make('reference')
                    ->searchable()
                    ->placeholder('—'),
            ])
            ->defaultSort('paid_on', 'desc')
            ->filters([
                SelectFilter::make('method')
                    ->options(fn () => PaymentMethods::all()),
                Filter::make('paid_on')
                    ->schema([
                        DatePicker::make('from'),
                        DatePicker::make('until'),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($q, $date) => $q->whereDate('paid_on', '>=', $date))
                        ->when($data['until'] ?? null, fn ($q, $date) => $q->whereDate('paid_on', '<=', $date))),
            ])
            ->recordUrl(fn (Payment $record) => ServiceResource::getUrl('edit', ['record' => $record->service_id]));
    }
}
