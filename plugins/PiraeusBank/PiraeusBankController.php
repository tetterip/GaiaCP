<?php

namespace Plugins\PiraeusBank;

use App\Filament\Client\Resources\PaymentNotices\PaymentNoticeResource;
use App\Plugins\PluginManager;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The client's way to the bank and back. pay() is the page that posts the payment's reference to the bank. The bank
 * sends the client's browser back to answer() (cross-site, so without the session: it only checks and records), which
 * passes it on to back(), in the client area, for the message.
 */
class PiraeusBankController
{
    /** The page that sends the client's browser to the bank's own page. */
    public function pay(string $reference, PiraeusBankPayments $payments): Response|RedirectResponse
    {
        abort_unless(app(PluginManager::class)->enabled('piraeusbank') && PiraeusBankPayments::configured(), 404);

        $payment = PiraeusBankPayment::query()->where('reference', $reference)->firstOrFail();
        $user = Filament::auth()->user();
        abort_unless($user !== null && (int) $user->client_id === (int) $payment->invoice?->client_id, 404);

        if ($payment->status !== PiraeusBankPayment::OPEN) {
            return redirect()->to(PaymentNoticeResource::getUrl('view', ['record' => $payment->invoice_id], panel: 'client'));
        }

        return AutoPostForm::response($payments->form($payment), __('Taking you to Piraeus Bank\'s secure page…'), __('Continue'));
    }

    /** The bank's answer on the success, fail and cancel addresses registered with it (GET or POST). */
    public function answer(Request $request, string $result, PiraeusBankPayments $payments): RedirectResponse
    {
        abort_unless(app(PluginManager::class)->enabled('piraeusbank'), 404);

        $answer = collect($request->all())->filter(fn ($value) => is_scalar($value) || $value === null)->map(fn ($value) => (string) $value)->all();
        $payment = $payments->answer($result, $answer);

        if ($payment === null) {
            return redirect()->to(Filament::getPanel('client')->getUrl(), 303);
        }

        return redirect()->route('filament.client.piraeusbank.back', ['reference' => $payment->reference], 303);
    }

    /** Back in the client area: the message for what came of the payment. */
    public function back(string $reference): RedirectResponse
    {
        $payment = PiraeusBankPayment::query()->where('reference', $reference)->first();
        $user = Filament::auth()->user();

        if ($payment === null || $user === null || (int) $user->client_id !== (int) $payment->invoice?->client_id) {
            return redirect()->to(Filament::getPanel('client')->getUrl());
        }

        $notification = match ($payment->status) {
            PiraeusBankPayment::PAID => Notification::make()->success()->title(__('Thank you, your payment is recorded.')),
            PiraeusBankPayment::UNMATCHED => Notification::make()->warning()->persistent()->title(__('Your card payment went through.'))
                ->body(__('We need to record it by hand; we will do so shortly. There is no need to pay again.')),
            PiraeusBankPayment::PENDING => Notification::make()->warning()->persistent()->title(__('Your IRIS payment has started but the bank has not confirmed it yet.'))
                ->body(__('Please do not pay again. The payment notice shows as paid once we see the payment.')),
            PiraeusBankPayment::CANCELLED => Notification::make()->warning()->title(__('The payment was cancelled. Nothing was taken.')),
            PiraeusBankPayment::FAILED => Notification::make()->danger()->persistent()->title(__('The bank did not accept the payment.'))
                ->body(__('Please try again, or choose another way to pay.')),
            default => Notification::make()->info()->persistent()->title(__('Thank you, your payment is being confirmed.'))
                ->body(__('The payment notice shows as paid once the bank confirms it.')),
        };
        $notification->send();

        return redirect()->to(PaymentNoticeResource::getUrl('view', ['record' => $payment->invoice_id], panel: 'client'));
    }
}
