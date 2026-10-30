<?php

namespace App\Filament\Resources\TaxInvoices\Tables;

use App\Filament\Resources\TaxInvoices\Pages\ViewTaxInvoice;
use App\Filament\Resources\TaxInvoices\TaxInvoiceResource;
use App\Models\TaxInvoice;
use App\Support\Currencies;
use App\Support\Money;
use Filament\Tables\Columns\Summarizers\Summarizer;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

class TaxInvoicesTable
{
    public static function configure(Table $table, bool $showClient = true): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('client', 'notice'))
            ->columns(array_values(array_filter([
                TextColumn::make('number')
                    ->searchable()
                    ->sortable()
                    ->description(fn (TaxInvoice $record) => $record->isCreditNote() ? __('Credit note') : null),
                $showClient ? TextColumn::make('client.last_name')
                    ->label('Client')
                    ->formatStateUsing(fn (TaxInvoice $record) => $record->client->name)
                    ->description(fn (TaxInvoice $record) => $record->client->company)
                    ->searchable(['first_name', 'last_name', 'company']) : null,
                TextColumn::make('date')
                    ->date()
                    ->sortable(),
                TextColumn::make('notice.number')
                    ->label('Payment notice')
                    ->searchable(),
                TextColumn::make('tax_cents')
                    ->label('Tax')
                    ->formatStateUsing(fn (TaxInvoice $record) => Money::format($record->tax_cents, $record->currency))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('total_cents')
                    ->label('Total')
                    ->formatStateUsing(fn (TaxInvoice $record) => Money::format($record->signedTotalCents(), $record->currency))
                    ->color(fn (TaxInvoice $record) => $record->isCreditNote() ? 'danger' : null)
                    ->sortable()
                    ->summarize(Summarizer::make()->label('Total')->using(fn (QueryBuilder $query) => Currencies::sumToBase($query, TaxInvoice::signedTotalSql()))->formatStateUsing(fn ($state) => Money::format((int) $state))),
                TextColumn::make('payment_methods')
                    ->label('Paid by')
                    ->state(fn (TaxInvoice $record) => $record->paymentMethods())
                    ->placeholder('—')
                    ->toggleable(),
            ])))
            ->defaultSort(fn (Builder $query) => $query->orderByDesc('date')->orderByDesc('id'))
            ->filters([
                SelectFilter::make('type')
                    ->options([TaxInvoice::INVOICE => __('Invoices'), TaxInvoice::CREDIT_NOTE => __('Credit notes')]),
                Filter::make('this_year')
                    ->label('This year')
                    ->query(fn (Builder $query) => $query->whereYear('date', today()->year)),
            ])
            ->recordActions([ViewTaxInvoice::downloadAction()->iconButton()->tooltip(__('Download PDF'))])
            ->recordUrl(fn (TaxInvoice $record) => TaxInvoiceResource::getUrl('view', ['record' => $record]));
    }
}
