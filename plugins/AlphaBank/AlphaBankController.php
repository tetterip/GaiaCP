<?php

namespace Plugins\AlphaBank;

use App\Filament\Client\Resources\PaymentNotices\PaymentNoticeResource;
use App\Plugins\PluginManager;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The client's way to the bank and back. pay() is the page that posts the signed form to the bank. The bank sends the
 * client's browser back to answer() (cross-site, so without the session: it only checks and records), which passes it
 * on to back(), in the client area, for the message.
 */
class AlphaBankController
{
    /** The page that sends the client's browser to the bank's own page, with the signed form. */
    public function pay(string $reference, AlphaBankPayments $payments): Response|RedirectResponse
    {
        abort_unless(app(PluginManager::class)->enabled('alphabank') && AlphaBankPayments::configured(), 404);

        $payment = AlphaBankPayment::query()->where('reference', $reference)->firstOrFail();
        $user = Filament::auth()->user();
        abort_unless($user !== null && (int) $user->client_id === (int) $payment->invoice?->client_id, 404);

        if ($payment->status !== AlphaBankPayment::OPEN) {
            return redirect()->to(PaymentNoticeResource::getUrl('view', ['record' => $payment->invoice_id], panel: 'client'));
        }

        return AutoPostForm::response($payments->form($payment), __('Taking you to Alpha Bank\'s secure page…'), __('Continue'));
    }

    /** The bank's answer (confirmUrl and cancelUrl), posted by the client's browser. */
    public function answer(Request $request, AlphaBankPayments $payments): RedirectResponse
    {
        abort_unless(app(PluginManager::class)->enabled('alphabank'), 404);

        // The raw body: values exactly as signed (Laravel's TrimStrings would change them)
        $payment = $payments->answer(Cardlink::parse((string) $request->getContent()));

        if ($payment === null) {
            return redirect()->to(Filament::getPanel('client')->getUrl(), 303);
        }

        return redirect()->route('filament.client.alphabank.back', ['reference' => $payment->reference], 303);
    }

    /** Back in the client area: the message for what came of the payment. */
    public function back(string $reference): RedirectResponse
    {
        $payment = AlphaBankPayment::query()->where('reference', $reference)->first();
        $user = Filament::auth()->user();

        if ($payment === null || $user === null || (int) $user->client_id !== (int) $payment->invoice?->client_id) {
            return redirect()->to(Filament::getPanel('client')->getUrl());
        }

        $notification = match ($payment->status) {
            AlphaBankPayment::PAID => Notification::make()->success()->title(__('Thank you, your payment is recorded.')),
            AlphaBankPayment::UNMATCHED => Notification::make()->warning()->persistent()->title(__('Your card payment went through.'))
                ->body(__('We need to record it by hand; we will do so shortly. There is no need to pay again.')),
            AlphaBankPayment::CANCELLED => Notification::make()->warning()->title(__('The payment was cancelled. Nothing was taken.')),
            AlphaBankPayment::FAILED => Notification::make()->danger()->persistent()->title(__('The bank did not accept the payment.'))
                ->body(__('Please try again, or choose another way to pay.')),
            default => Notification::make()->info()->persistent()->title(__('Thank you, your payment is being confirmed.'))
                ->body(__('The payment notice shows as paid once the bank confirms it.')),
        };
        $notification->send();

        return redirect()->to(PaymentNoticeResource::getUrl('view', ['record' => $payment->invoice_id], panel: 'client'));
    }
}
