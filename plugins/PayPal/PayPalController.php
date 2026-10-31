<?php

namespace Plugins\PayPal;

use App\Filament\Client\Resources\PaymentNotices\PaymentNoticeResource;
use App\Plugins\PluginManager;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * PayPal's two ways back: the client's browser after approving (return), and PayPal's webhook (webhook). Neither is
 * believed: both only name an order GaiaCP opened, and PayPalPayments::sync() reads that order from PayPal's API with
 * the app's own keys before taking or recording anything. So the webhook needs no signing secret.
 */
class PayPalController
{
    /** The events to tick on the app's webhook in PayPal (Settings → PayPal lists them). */
    public const EVENTS = [
        'CHECKOUT.ORDER.APPROVED',
        'PAYMENT.CAPTURE.COMPLETED',
        'PAYMENT.CAPTURE.DENIED',
    ];

    public function webhook(Request $request, PayPalPayments $payments): JsonResponse
    {
        abort_unless(app(PluginManager::class)->enabled('paypal') && PayPalApi::configured(), 404);

        $type = (string) $request->json('event_type');

        $orderId = match (true) {
            str_starts_with($type, 'CHECKOUT.ORDER.') => $request->json('resource.id'),
            str_starts_with($type, 'PAYMENT.CAPTURE.') => $request->json('resource.supplementary_data.related_ids.order_id'),
            default => null,
        };

        $order = is_string($orderId) && $orderId !== '' ? PayPalOrder::query()->where('order_id', $orderId)->first() : null;

        if ($order !== null) {
            try {
                $payments->sync($order);
            } catch (PayPalException) {
                return response()->json(status: 503); // PayPal sends it again later
            }
        }

        return response()->json(['received' => true]);
    }

    public function return(Request $request, PayPalPayments $payments): RedirectResponse
    {
        abort_unless(app(PluginManager::class)->enabled('paypal') && PayPalApi::configured(), 404);

        $order = PayPalOrder::query()->where('order_id', (string) $request->query('token'))->first();

        if ($order === null) {
            return redirect()->to(Filament::getPanel('client')->getUrl());
        }

        try {
            $order = $payments->sync($order);
        } catch (PayPalException) {
            $order->refresh(); // PayPal will tell us through the webhook
        }

        $notification = match ($order->status) {
            PayPalOrder::PAID => Notification::make()->success()->title(__('Thank you, your payment is recorded.')),
            PayPalOrder::UNMATCHED => Notification::make()->warning()->persistent()->title(__('Your PayPal payment went through.'))
                ->body(__('We need to record it by hand; we will do so shortly. There is no need to pay again.')),
            PayPalOrder::CANCELLED => Notification::make()->warning()->persistent()->title(__('The payment notice changed, so nothing was taken.'))
                ->body(__('Please check the notice and pay it again.')),
            PayPalOrder::FAILED => Notification::make()->danger()->persistent()->title(__('PayPal did not complete the payment.'))
                ->body(__('Please try again, or choose another way to pay.')),
            default => Notification::make()->info()->persistent()->title(__('Thank you, your payment is being confirmed.'))
                ->body(__('The payment notice shows as paid once PayPal confirms it.')),
        };
        $notification->send();

        return redirect()->to(PaymentNoticeResource::getUrl('view', ['record' => $order->invoice_id], panel: 'client'));
    }
}
