<?php

namespace App\Filament\Resources\TaxInvoices\Pages;

use App\Filament\Resources\TaxInvoices\TaxInvoiceResource;
use App\Invoicing\InvoicePdf;
use App\Invoicing\IssueInvoice;
use App\Models\TaxInvoice;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

/** One invoice, read only: it is never changed after it was issued. */
class ViewTaxInvoice extends ViewRecord
{
    protected static string $resource = TaxInvoiceResource::class;

    public function getTitle(): string
    {
        return __('Invoice :number', ['number' => $this->getRecord()->number]);
    }

    protected function getHeaderActions(): array
    {
        return [
            self::downloadAction(),
            Action::make('sendTaxInvoice')
                ->label('Send email')
                ->icon(Heroicon::OutlinedEnvelope)
                ->color('gray')
                ->requiresConfirmation()
                ->modalDescription(fn (TaxInvoice $record) => __('Sends the Invoice Issued email with the PDF to :email now, even if that email is switched off on the Email templates page.', ['email' => $record->client->email]))
                ->action(function (TaxInvoice $record) {
                    if (! app(IssueInvoice::class)->email($record, evenIfOff: true)) {
                        Notification::make()->title(__('Not sent'))->body(__('See the activity log for the error.'))->danger()->send();

                        return;
                    }

                    Notification::make()->title(__('Email sent'))->success()->send();
                }),
        ];
    }

    /** Download PDF, also in the client area. */
    public static function downloadAction(string $name = 'downloadPdf'): Action
    {
        return Action::make($name)
            ->label('Download PDF')
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->action(fn (TaxInvoice $record) => response()->streamDownload(
                fn () => print (InvoicePdf::content($record)),
                InvoicePdf::filename($record),
                ['Content-Type' => 'application/pdf'],
            ));
    }
}
