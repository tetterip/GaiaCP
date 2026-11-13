<?php

namespace Plugins\Iris;

use App\Actions\PaymentClaims;
use App\Actions\RecordInvoicePayment;
use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Payments\PaymentModule;
use App\Plugins\Plugin;
use App\Providers\AppServiceProvider;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;

/**
 * Payments by IRIS, Greece's instant bank payments: the payee, IRIS number and the bank's fixed QR code on the client's
 * unpaid payment notices ("Pay with IRIS"). IRIS has no public API for shops, so payments are recorded by hand.
 */
class IrisPlugin extends Plugin implements PaymentModule
{
    public function id(): string
    {
        return 'iris';
    }

    public function name(): string
    {
        return 'IRIS';
    }

    public function description(): string
    {
        return __('Clients pay their payment notices by IRIS from their bank\'s app: your IRIS number and your bank\'s QR code are shown on unpaid notices. You record the payments by hand.');
    }

    public function category(): string
    {
        return 'Payments';
    }

    /** Off in a new install, and when it first appears after an update: switched on under Settings → Payment methods. */
    public function enabledByDefault(): bool
    {
        return false;
    }

    public function key(): string
    {
        return 'iris';
    }

    public function label(): string
    {
        return 'IRIS';
    }

    public function instructions(): ?string
    {
        return Iris::instructions();
    }

    public function settingsPage(): ?string
    {
        return IrisSettings::class;
    }

    public function pages(): array
    {
        return [IrisSettings::class];
    }

    public function settingKeys(): array
    {
        return ['iris.payee', 'iris.number', 'iris.qr', 'iris.note'];
    }

    public function clientPaymentNoticeActions(): array
    {
        if (! Iris::configured()) {
            return [];
        }

        return [
            Action::make('payWithIris')
                ->label('Pay with IRIS')
                ->icon(Heroicon::OutlinedQrCode)
                ->color('gray')
                ->visible(fn (Invoice $record) => $record->status === InvoiceStatus::Unpaid && self::amountDue($record) > 0)
                ->modalHeading(__('Pay with IRIS'))
                ->modalWidth('md')
                ->modalContent(fn (Invoice $record) => self::details($record))
                // "I've paid": tells us to look for the transfer (PaymentClaims); the notice stays unpaid until we record it
                ->modalSubmitActionLabel(__('I\'ve paid'))
                ->modalSubmitAction(fn (Action $action, Invoice $record) => PaymentClaims::canClaim($record) ? $action : false)
                ->modalCancelActionLabel(__('Close'))
                ->action(function (Invoice $record) {
                    if (! PaymentClaims::canClaim($record)) {
                        return;
                    }

                    app(PaymentClaims::class)->claim($record, $this->key());
                    Notification::make()->success()
                        ->title(__('Thank you, we will check for your payment'))
                        ->body(__('The notice shows as paid once we see the payment in our account.'))
                        ->send();
                }),
        ];
    }

    /** What the notice still owes: its unpaid lines, with the late fees. */
    public static function amountDue(Invoice $invoice): int
    {
        return (int) app(RecordInvoicePayment::class)->unpaid($invoice)->sum('total_cents');
    }

    /** The QR code, the details and the reference to write, for the modal. */
    public static function details(Invoice $invoice): HtmlString
    {
        $rows = array_filter([
            __('Amount') => Money::format(self::amountDue($invoice), $invoice->currency),
            __('Payee') => Iris::payee(),
            __('IRIS number') => Iris::number(),
            __('Reason') => $invoice->number,
        ], fn ($value) => filled($value));

        $html = '<div style="display:flex;flex-direction:column;gap:1rem;align-items:center;text-align:center">';

        if (($qr = Iris::qrDataUri()) !== null) {
            $html .= '<img src="'.e($qr).'" alt="'.e(__('IRIS QR code')).'" style="width:100%;max-width:240px;height:auto;border-radius:0.5rem;background:#fff;padding:0.5rem">';
        }

        $html .= '<dl style="display:grid;grid-template-columns:auto auto;gap:0.25rem 1rem;text-align:start">';
        foreach ($rows as $label => $value) {
            $html .= '<dt style="opacity:0.7">'.e($label).'</dt><dd><strong>'.e($value).'</strong></dd>';
        }
        $html .= '</dl>';

        $html .= '<p style="font-size:0.875rem;opacity:0.8">'.e(__('In your bank\'s app choose IRIS, scan the QR code or enter the IRIS number, and write :number as the reason. The notice shows as paid once we see the payment in our account.', ['number' => $invoice->number])).'</p>';

        if (filled(Iris::note())) {
            $html .= '<p style="font-size:0.875rem">'.nl2br(e(Iris::note())).'</p>';
        }

        $html .= PaymentClaims::waiting($invoice)
            ? '<p style="font-size:0.875rem;font-weight:600">'.e(__('You told us on :date that you paid. We are checking for it.', ['date' => $invoice->payment_claimed_at->format(AppServiceProvider::dateFormat().' H:i')])).'</p>'
            : '<p style="font-size:0.875rem">'.e(__('Once you have paid, press I\'ve paid so we know to look for it.')).'</p>';

        return new HtmlString($html.'</div>');
    }
}
