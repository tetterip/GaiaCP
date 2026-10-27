<?php

namespace Plugins\Stripe;

use App\Filament\Client\Resources\PaymentNotices\PaymentNoticeResource;
use App\Plugins\PluginManager;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Stripe's two ways back: the client's browser after paying (return), and Stripe's signed webhook (webhook). Both
 * hand the session to StripeCheckouts::complete(), which records it once; the browser's visit counts only for what
 * Stripe's API then says about the session, never for the visit itself.
 */
class StripeController
{
    /** The events to tick on the webhook endpoint in Stripe (Settings → Stripe lists them). */
    public const EVENTS = [
        'checkout.session.completed',
        'checkout.session.async_payment_succeeded',
        'checkout.session.async_payment_failed',
        'checkout.session.expired',
    ];

    public function webhook(Request $request, StripeCheckouts $checkouts): JsonResponse
    {
        abort_unless(app(PluginManager::class)->enabled('stripe'), 404);

        if (! WebhookSignature::valid($request->getContent(), $request->header('Stripe-Signature'), StripeApi::webhookSecrets())) {
            return response()->json(status: 400); // not signed by Stripe with a secret saved here
        }

        $event = json_decode($request->getContent(), true);
        $session = (array) data_get($event, 'data.object', []);

        match (data_get($event, 'type')) {
            'checkout.session.completed', 'checkout.session.async_payment_succeeded' => $checkouts->complete($session),
            'checkout.session.async_payment_failed' => $checkouts->close($session, StripeCheckout::FAILED),
            'checkout.session.expired' => $checkouts->close($session, StripeCheckout::EXPIRED),
            default => null,
        };

        return response()->json(['received' => true]);
    }

    public function return(Request $request, StripeApi $stripe, StripeCheckouts $checkouts): RedirectResponse
    {
        abort_unless(app(PluginManager::class)->enabled('stripe') && StripeApi::configured(), 404);

        $checkout = StripeCheckout::query()->where('session_id', (string) $request->query('session_id'))->first();

        if ($checkout === null) {
            return redirect()->to(Filament::getPanel('client')->getUrl());
        }

        if ($checkout->status === StripeCheckout::OPEN) {
            try {
                $checkout = $checkouts->complete($stripe->checkoutSession($checkout->session_id)) ?? $checkout;
            } catch (StripeException) {
                // Stripe will tell us through the webhook.
            }
        }

        $notification = match ($checkout->status) {
            StripeCheckout::PAID => Notification::make()->success()->title(__('Thank you, your payment is recorded.')),
            StripeCheckout::UNMATCHED => Notification::make()->warning()->persistent()->title(__('Your card payment went through.'))
                ->body(__('We need to record it by hand; we will do so shortly. There is no need to pay again.')),
            default => Notification::make()->info()->persistent()->title(__('Thank you, your payment is being confirmed.'))
                ->body(__('The payment notice shows as paid once Stripe confirms it.')),
        };
        $notification->send();

        return redirect()->to(PaymentNoticeResource::getUrl('view', ['record' => $checkout->invoice_id], panel: 'client'));
    }
}
