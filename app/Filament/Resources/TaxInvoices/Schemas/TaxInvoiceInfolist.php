<?php

namespace App\Filament\Resources\TaxInvoices\Schemas;

use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Resources\Invoices\Schemas\InvoiceInfolist;
use App\Filament\Resources\TaxInvoices\TaxInvoiceResource;
use App\Models\Invoice;
use App\Models\TaxInvoice;
use App\Providers\AppServiceProvider;
use App\Support\Money;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class TaxInvoiceInfolist
{
    public static function configure(Schema $schema): Schema
    {
        $money = fn (string $field) => fn (TaxInvoice $record) => Money::format($record->{$field}, $record->currency);

        return $schema
            ->components([
                Section::make()
                    ->columns(4)
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('client.last_name')
                            ->label('Client')
                            ->state(fn (TaxInvoice $record) => $record->client->displayName())
                            ->url(fn (TaxInvoice $record) => ClientResource::getUrl('edit', ['record' => $record->client_id])),
                        TextEntry::make('status')
                            ->state(fn (TaxInvoice $record) => $record->statusLabel())
                            ->badge()
                            ->color(fn (TaxInvoice $record) => $record->statusColor()),
                        TextEntry::make('date')
                            ->date(AppServiceProvider::dateFormat()),
                        TextEntry::make('notice.due_date')
                            ->label('Due')
                            ->date(AppServiceProvider::dateFormat())
                            ->placeholder('—')
                            ->hidden(fn (TaxInvoice $record) => $record->isCreditNote()),
                        TextEntry::make('paid_on')
                            ->date(AppServiceProvider::dateFormat())
                            ->placeholder('—')
                            ->hidden(fn (TaxInvoice $record) => $record->isCreditNote()),
                        TextEntry::make('creditedInvoice.number')
                            ->label('Credits invoice')
                            ->url(fn (TaxInvoice $record) => $record->credited_invoice_id ? TaxInvoiceResource::getUrl('view', ['record' => $record->credited_invoice_id]) : null)
                            ->visible(fn (TaxInvoice $record) => $record->isCreditNote()),
                        TextEntry::make('notice.number')
                            ->label('Payment notice')
                            ->url(fn (TaxInvoice $record) => $record->invoice_id ? InvoiceResource::getUrl('view', ['record' => $record->invoice_id]) : null),
                        TextEntry::make('subtotal_cents')
                            ->label(fn (TaxInvoice $record) => $record->tax_inclusive ? 'Before tax' : 'Subtotal')
                            ->state($money('subtotal_cents')),
                        TextEntry::make('tax_cents')
                            ->label(fn (TaxInvoice $record) => $record->taxLabel() ? $record->taxLabel().($record->tax_inclusive ? ' (included)' : '') : 'Tax')
                            ->state($money('tax_cents'))
                            ->helperText(fn (TaxInvoice $record) => $record->tax_note),
                        TextEntry::make('total_cents')
                            ->label('Total')
                            ->weight('bold')
                            ->state($money('total_cents')),
                        TextEntry::make('balance')
                            ->label('Balance due')
                            ->state(fn (TaxInvoice $record) => Money::format(0, $record->currency))
                            ->weight('bold')
                            ->color('success')
                            ->hidden(fn (TaxInvoice $record) => $record->isCreditNote()),
                        TextEntry::make('payment_methods')
                            ->label('Paid by')
                            ->state(fn (TaxInvoice $record) => $record->paymentMethods())
                            ->placeholder('—')
                            ->hidden(fn (TaxInvoice $record) => $record->isCreditNote()),
                        TextEntry::make('reason')
                            ->label('Reason')
                            ->formatStateUsing(fn (?string $state) => $state === null ? null : __($state))
                            ->placeholder('—')
                            ->visible(fn (TaxInvoice $record) => $record->isCreditNote()),
                        TextEntry::make('seller')
                            ->label('From')
                            ->state(fn (TaxInvoice $record) => self::sellerLines($record->seller))
                            ->listWithLineBreaks()
                            ->columnSpan(2),
                        TextEntry::make('bill_to')
                            ->label('For')
                            ->state(fn (TaxInvoice $record) => InvoiceInfolist::billToLines(
                                (object) array_merge(array_fill_keys([...Invoice::BILL_TO, 'custom'], null), $record->bill_to)
                            ))
                            ->listWithLineBreaks()
                            ->columnSpan(2),
                    ]),
            ]);
    }

    /** An invoice's credit notes after its lines: date, number, reason and amount. */
    public static function creditNotesSection(TaxInvoice $invoice): Section
    {
        return Section::make(__('Credit notes'))
            ->columnSpanFull()
            ->visible(fn () => ! $invoice->isCreditNote() && $invoice->creditNotes()->exists())
            ->schema([
                RepeatableEntry::make('creditNotes')
                    ->hiddenLabel()
                    ->state(fn () => $invoice->creditNotes()->orderBy('id')->get())
                    ->table([
                        TableColumn::make(__('Date')),
                        TableColumn::make(__('Number')),
                        TableColumn::make(__('Reason')),
                        TableColumn::make(__('Total')),
                    ])
                    ->schema([
                        TextEntry::make('date')->date(AppServiceProvider::dateFormat()),
                        TextEntry::make('number')
                            ->color('primary')
                            ->url(fn (TaxInvoice $record) => TaxInvoiceResource::getUrl('view', ['record' => $record])),
                        TextEntry::make('reason')
                            ->formatStateUsing(fn (?string $state) => $state === null ? null : __($state))
                            ->placeholder('—'),
                        TextEntry::make('total_cents')
                            ->state(fn (TaxInvoice $record) => Money::format(-$record->total_cents, $record->currency))
                            ->color('danger')
                            ->weight('bold'),
                    ]),
            ]);
    }

    /** @param  array<string, string|null>  $seller  @return list<string> */
    private static function sellerLines(array $seller): array
    {
        return array_values(array_filter([
            $seller['name'] ?? null,
            ...preg_split('/\R/', (string) ($seller['address'] ?? '')),
            filled($seller['vat_number'] ?? null) ? __('VAT number: :number', ['number' => $seller['vat_number']]).(filled($seller['tax_office'] ?? null) ? ", {$seller['tax_office']}" : '') : null,
            $seller['email'] ?? null,
            $seller['phone'] ?? null,
        ]));
    }
}
