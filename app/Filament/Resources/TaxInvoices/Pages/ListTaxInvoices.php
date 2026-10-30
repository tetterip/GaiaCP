<?php

namespace App\Filament\Resources\TaxInvoices\Pages;

use App\Filament\Resources\TaxInvoices\TaxInvoiceResource;
use App\Models\TaxInvoice;
use App\Providers\AppServiceProvider;
use App\Reports\Csv;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Every invoice and credit note, newest first. */
class ListTaxInvoices extends ListRecords
{
    protected static string $resource = TaxInvoiceResource::class;

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

    /**
     * The rows that match the filters and search, for the accountant: one per invoice or credit note, with the client
     * details printed on it. A credit note's amounts are negative, so a column adds up to the period's turnover.
     */
    public function csv(): StreamedResponse
    {
        $query = $this->getFilteredSortedTableQuery()->with(['notice', 'creditedInvoice']);
        $date = fn ($value) => $value?->format(AppServiceProvider::dateFormat());

        return Csv::download(
            'invoices-'.today()->format('Y-m-d').'.csv',
            [__('Type'), __('Number'), __('Date'), __('Credits invoice'), __('Client'), __('Company'), __('VAT number'),
                __('Tax office'), __('Country'), __('Payment notice'), __('Currency'), __('Before tax'), __('Tax'), __('Tax rate'),
                __('Total'), __('Paid on'), __('Paid by'), __('Tax note'), __('Reason')],
            (function () use ($query, $date) {
                foreach ($query->lazy(200) as $invoice) {
                    /** @var TaxInvoice $invoice */
                    $to = $invoice->bill_to;
                    $sign = $invoice->isCreditNote() ? -1 : 1;

                    yield [
                        $invoice->typeLabel(), $invoice->number, $date($invoice->date), $invoice->creditedInvoice?->number,
                        trim(($to['first_name'] ?? '').' '.($to['last_name'] ?? '')), $to['company'] ?? null, $to['vat_number'] ?? null,
                        $to['tax_office'] ?? null, $to['country'] ?? null, $invoice->notice?->number, $invoice->currency,
                        Money::toDecimal($sign * $invoice->subtotal_cents), Money::toDecimal($sign * $invoice->tax_cents),
                        $invoice->tax_rate, Money::toDecimal($sign * $invoice->total_cents), $date($invoice->paid_on),
                        $invoice->paymentMethods(), $invoice->tax_note, $invoice->reason !== null ? __($invoice->reason) : null,
                    ];
                }
            })(),
        );
    }
}
