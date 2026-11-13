<?php

namespace App\Filament\Client\Resources\PaymentNotices\Pages;

use App\Actions\PaymentClaims;
use App\Credit\ApplyCredit;
use App\Filament\Client\Resources\PaymentNotices\PaymentNoticeResource;
use App\Invoicing\InvoicePdf;
use App\Models\Invoice;
use App\Payments\PaymentMethods;
use App\Plugins\PluginManager;
use App\Providers\AppServiceProvider;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
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
            // Pay online, from payment plugins (e.g. Stripe's "Pay by card"): one button, a menu when there are several
            ...$this->payOnline(),
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

    /** Under the title while the client's "I've paid" waits for us to find the payment (App\Actions\PaymentClaims). */
    public function getSubheading(): ?string
    {
        $record = $this->getRecord();

        if (! $record instanceof Invoice || ! PaymentClaims::waiting($record)) {
            return null;
        }

        return __('You told us on :date that you paid by :method. The notice shows as paid once we find the payment.', [
            'date' => $record->payment_claimed_at->format(AppServiceProvider::dateFormat().' H:i'),
            'method' => PaymentMethods::label($record->payment_claim_method),
        ]);
    }

    /**
     * The payment plugins' buttons for this notice: one that can take it shows as it is, several in a "Pay online" menu
     * (which leaves out the hidden ones), so the client picks how to pay (card through Stripe or a bank, PayPal, IRIS…).
     *
     * @return list<Action|ActionGroup>
     */
    private function payOnline(): array
    {
        $record = $this->getRecord();
        $actions = collect(app(PluginManager::class)->clientPaymentNoticeActions());
        $available = $actions->filter(fn (Action $action) => (clone $action)->record($record)->isVisible());

        if ($available->count() <= 1) {
            return $actions->all();
        }

        return [
            ActionGroup::make($actions->all())
                ->label(__('Pay online'))
                ->icon(Heroicon::OutlinedCreditCard)
                ->color('primary')
                ->button()
                ->dropdownPlacement('bottom-end'),
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
