<?php

namespace App\Filament\Resources\Invoices\Schemas;

use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\TaxInvoices\TaxInvoiceResource;
use App\Models\Invoice;
use App\Providers\AppServiceProvider;
use App\Support\ClientFields;
use App\Support\Money;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class InvoiceInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->columns(4)
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('client.last_name')
                            ->label('Client')
                            ->state(fn (Invoice $record) => $record->client->displayName())
                            ->url(fn (Invoice $record) => ClientResource::getUrl('edit', ['record' => $record->client_id])),
                        TextEntry::make('status')
                            ->badge(),
                        TextEntry::make('date')
                            ->date(AppServiceProvider::dateFormat()),
                        TextEntry::make('due_date')
                            ->label('Due')
                            ->date(AppServiceProvider::dateFormat())
                            ->color(fn (Invoice $record) => $record->isOverdue() ? 'danger' : null),
                        TextEntry::make('subtotal_cents')
                            ->label(fn (Invoice $record) => $record->tax_inclusive ? 'Before tax' : 'Subtotal')
                            ->state(fn (Invoice $record) => Money::format($record->subtotal_cents, $record->currency)),
                        TextEntry::make('tax_cents')
                            ->label(fn (Invoice $record) => $record->taxLabel() ? $record->taxLabel().($record->tax_inclusive ? ' (included)' : '') : 'Tax')
                            ->state(fn (Invoice $record) => Money::format($record->tax_cents, $record->currency))
                            ->helperText(fn (Invoice $record) => $record->tax_note),
                        TextEntry::make('total_cents')
                            ->label('Total')
                            ->weight('bold')
                            ->state(fn (Invoice $record) => Money::format($record->total_cents, $record->currency)),
                        TextEntry::make('paid_on')
                            ->date(AppServiceProvider::dateFormat())
                            ->placeholder('—'),
                        TextEntry::make('emailed_at')
                            ->label('Emailed')
                            ->dateTime(AppServiceProvider::dateFormat().' H:i')
                            ->placeholder(__('Not yet')),
                        TextEntry::make('order_id')
                            ->label('Order')
                            ->formatStateUsing(fn ($state) => "#{$state}")
                            ->url(fn (Invoice $record) => $record->order_id ? OrderResource::getUrl('view', ['record' => $record->order_id]) : null)
                            ->placeholder(__('Renewal')),
                        TextEntry::make('taxInvoice.number')
                            ->label('Invoice')
                            ->url(fn (Invoice $record) => $record->taxInvoice ? TaxInvoiceResource::getUrl('view', ['record' => $record->taxInvoice]) : null)
                            ->visible(fn (Invoice $record) => $record->taxInvoice !== null),
                        TextEntry::make('bill_to')
                            ->label('For')
                            ->state(fn (Invoice $record) => self::billToLines($record->billTo()))
                            ->listWithLineBreaks()
                            ->hint(fn (Invoice $record) => $record->billToChanged() ? __('The client\'s details have changed since') : null)
                            ->hintColor('warning')
                            ->columnSpan(2),
                        TextEntry::make('notes')
                            ->placeholder('—')
                            ->columnSpan(2),
                    ]),
            ]);
    }

    /** @return list<string> the "For" block of a notice or an invoice, as the client sees it (Invoice::billTo()) */
    public static function billToLines(object $to): array
    {
        $name = trim($to->first_name.' '.$to->last_name);

        return array_values(array_filter([
            $to->company ?: $name,
            $to->company ? $name : null,
            $to->address1,
            $to->address2,
            trim($to->postcode.' '.$to->city),
            $to->state,
            filled($to->vat_number) ? __('VAT number: :number', ['number' => $to->vat_number]).(filled($to->tax_office) ? ", {$to->tax_office}" : '') : null,
            ...ClientFields::noticeLines($to->custom),
        ]));
    }
}
