<?php

namespace Plugins\NexiXpay;

use App\Filament\Client\Resources\PaymentNotices\PaymentNoticeResource;
use App\Plugins\PluginManager;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Nexi's two ways back: the client's browser from Nexi's page (return, also for Cancel), and Nexi's server-to-server
 * notification (notification). Neither is believed: both only name an order GaiaCP opened, and
 * NexiXpayPayments::sync() reads that order from Nexi's API with the merchant's key before recording anything. The
 * notification must also carry the payment's own security token, so a stranger can't make GaiaCP call Nexi for it.
 */
class NexiXpayController
{
    public function notification(Request $request, NexiXpayPayments $payments): JsonResponse
    {
        abort_unless(app(PluginManager::class)->enabled('nexi-xpay') && NexiXpayApi::configured(), 404);

        $orderId = $request->json('operation.orderId');
        $token = $request->json('securityToken');
        $payment = is_string($orderId) && $orderId !== '' ? NexiXpayPayment::query()->where('order_id', $orderId)->first() : null;

        if ($payment === null || ! is_string($token) || blank($payment->security_token) || ! hash_equals((string) $payment->security_token, $token)) {
            return response()->json(['outcome' => 'KO'], 400);
        }

        try {
            $payments->sync($payment);
        } catch (NexiXpayException) {
            return response()->json(['outcome' => 'KO'], 503); // Nexi sends it again later
        }

        return response()->json(['outcome' => 'OK']);
    }

    public function return(Request $request, NexiXpayPayments $payments): RedirectResponse
    {
        abort_unless(app(PluginManager::class)->enabled('nexi-xpay') && NexiXpayApi::configured(), 404);

        $payment = NexiXpayPayment::query()->where('order_id', (string) $request->query('order'))->first();
        $user = Filament::auth()->user();

        if ($payment === null || $user === null || (int) $user->client_id !== (int) $payment->invoice?->client_id) {
            return redirect()->to(Filament::getPanel('client')->getUrl());
        }

        try {
            $payment = $payments->sync($payment, cancelled: $request->boolean('cancelled'));
        } catch (NexiXpayException) {
            $payment->refresh(); // Nexi's notification, or the hourly look, records it
        }

        $notification = match ($payment->status) {
            NexiXpayPayment::PAID => Notification::make()->success()->title(__('Thank you, your payment is recorded.')),
            NexiXpayPayment::UNMATCHED => Notification::make()->warning()->persistent()->title(__('Your card payment went through.'))
                ->body(__('We need to record it by hand; we will do so shortly. There is no need to pay again.')),
            NexiXpayPayment::CANCELLED => Notification::make()->warning()->title(__('The payment was cancelled. Nothing was taken.')),
            NexiXpayPayment::FAILED => Notification::make()->danger()->persistent()->title(__('The bank did not accept the payment.'))
                ->body(__('Please try again, or choose another way to pay.')),
            default => Notification::make()->info()->persistent()->title(__('Thank you, your payment is being confirmed.'))
                ->body(__('The payment notice shows as paid once the bank confirms it.')),
        };
        $notification->send();

        return redirect()->to(PaymentNoticeResource::getUrl('view', ['record' => $payment->invoice_id], panel: 'client'));
    }
}
