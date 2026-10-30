<?php

namespace App\Filament\Client\Resources\PaymentNotices\Pages;

use App\Credit\ApplyCredit;
use App\Filament\Client\Resources\PaymentNotices\PaymentNoticeResource;
use App\Invoicing\InvoicePdf;
use App\Models\Invoice;
use App\Plugins\PluginManager;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Throwable;

class ViewPaymentNotice extends ViewRecord
{
    protected static string $resource = PaymentNoticeResource::class;

    public function getTitle(): string
    {
        return __('Payment notice :number', ['number' => $this->getRecord()->number]);
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->payWithCredit(),
            // Pay online, from payment plugins (e.g. Stripe's "Pay by card")
            ...app(PluginManager::class)->clientPaymentNoticeActions(),
            // A paid notice's invoice (Settings → Invoices), as a PDF
            Action::make('downloadInvoice')
                ->label(fn (Invoice $record) => __('Invoice :number', ['number' => $record->taxInvoice?->number]))
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->visible(fn (Invoice $record) => $record->taxInvoice !== null)
                ->action(fn (Invoice $record) => response()->streamDownload(
                    fn () => print (InvoicePdf::content($record->taxInvoice)),
                    InvoicePdf::filename($record->taxInvoice),
                    ['Content-Type' => 'application/pdf'],
                )),
            // The browser's print dialog, which also saves a PDF; the panel's print styles leave only the notice
            Action::make('print')
                ->label('Print or save as PDF')
                ->icon(Heroicon::OutlinedPrinter)
                ->color('gray')
                ->alpineClickHandler('window.print()'),
        ];
    }

    /** Pays the lines the client's credit covers in full (App\Credit\ApplyCredit). */
    private function payWithCredit(): Action
    {
        return Action::make('payWithCredit')
            ->label('Pay with credit')
            ->icon(Heroicon::OutlinedWallet)
            ->visible(fn (Invoice $record) => app(ApplyCredit::class)->coverable($record) > 0)
            ->requiresConfirmation()
            ->modalDescription(fn (Invoice $record) => __('Pays :amount of this payment notice from your credit of :balance.', [
                'amount' => Money::format(app(ApplyCredit::class)->coverable($record), $record->currency),
                'balance' => Money::format((int) $record->client->credit_cents, $record->currency),
            ]))
            ->action(function (Invoice $record) {
                try {
                    $paid = app(ApplyCredit::class)->toInvoice($record);
                } catch (Throwable $e) {
                    report($e);
                    Notification::make()->title(__('The payment could not be completed. Please contact us.'))->danger()->send();

                    return;
                }

                Notification::make()->title(__(':amount paid from credit', ['amount' => Money::format($paid, $record->currency)]))->success()->send();
                $this->redirect(PaymentNoticeResource::getUrl('view', ['record' => $record]));
            });
    }
}
