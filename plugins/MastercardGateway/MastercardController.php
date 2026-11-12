<?php

namespace Plugins\MastercardGateway;

use App\Filament\Client\Resources\PaymentNotices\PaymentNoticeResource;
use App\Plugins\PluginManager;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The client's way to the gateway and back. pay() is a small page that loads the gateway's checkout script and opens
 * its payment page for the session. The gateway sends the client back to return(), which reads the order from the
 * gateway (MastercardPayments::sync()) before saying anything; the resultIndicator it carries is not needed for that.
 */
class MastercardController
{
    public function pay(string $order): Response|RedirectResponse
    {
        abort_unless(app(PluginManager::class)->enabled('mastercard') && MastercardApi::configured(), 404);

        $payment = $this->own($order);
        abort_if($payment === null, 404);

        if ($payment->status !== MastercardPayment::OPEN || blank($payment->session_id)) {
            return redirect()->to(PaymentNoticeResource::getUrl('view', ['record' => $payment->invoice_id], panel: 'client'));
        }

        $message = __('Taking you to the secure payment page…');
        $html = '<!DOCTYPE html><html lang="'.e(str_replace('_', '-', app()->getLocale())).'"><head><meta charset="utf-8">'
            .'<meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">'
            .'<title>'.e($message).'</title>'
            .'<style>body{font-family:system-ui,sans-serif;display:flex;min-height:90vh;align-items:center;justify-content:center;text-align:center;color:#333;background:#fff}'
            .'button{font:inherit;padding:.6em 1.4em;margin-top:1em;border-radius:.4em;border:1px solid #888;background:#f4f4f4;cursor:pointer}'
            .'@media (prefers-color-scheme:dark){body{color:#ddd;background:#18181b}button{background:#27272a;color:#ddd}}</style>'
            .'<script src="'.e(MastercardApi::checkoutScript()).'"></script></head><body><div>'
            .'<p>'.e($message).'</p><button type="button" id="pay">'.e(__('Continue')).'</button></div>'
            .'<script>function go(){Checkout.configure({session:{id:'.json_encode($payment->session_id).'}});Checkout.showPaymentPage();}'
            .'document.getElementById("pay").addEventListener("click",go);go();</script></body></html>';

        return response($html)->header('Cache-Control', 'no-store');
    }

    public function return(Request $request, MastercardPayments $payments): RedirectResponse
    {
        abort_unless(app(PluginManager::class)->enabled('mastercard') && MastercardApi::configured(), 404);

        $payment = $this->own((string) $request->query('order'));

        if ($payment === null) {
            return redirect()->to(Filament::getPanel('client')->getUrl());
        }

        try {
            $payment = $payments->sync($payment, returned: true);
        } catch (MastercardException) {
            $payment->refresh(); // the hourly look records it
        }

        $notification = match ($payment->status) {
            MastercardPayment::PAID => Notification::make()->success()->title(__('Thank you, your payment is recorded.')),
            MastercardPayment::UNMATCHED => Notification::make()->warning()->persistent()->title(__('Your card payment went through.'))
                ->body(__('We need to record it by hand; we will do so shortly. There is no need to pay again.')),
            MastercardPayment::FAILED => Notification::make()->danger()->persistent()->title(__('The bank did not accept the payment.'))
                ->body(__('Please try again, or choose another way to pay.')),
            default => Notification::make()->info()->persistent()->title(__('Thank you, your payment is being confirmed.'))
                ->body(__('The payment notice shows as paid once the bank confirms it.')),
        };
        $notification->send();

        return redirect()->to(PaymentNoticeResource::getUrl('view', ['record' => $payment->invoice_id], panel: 'client'));
    }

    /** The signed-in client's own payment with that order ID. */
    private function own(string $order): ?MastercardPayment
    {
        $payment = $order !== '' ? MastercardPayment::query()->where('order_id', $order)->first() : null;
        $user = Filament::auth()->user();

        return $payment !== null && $user !== null && (int) $user->client_id === (int) $payment->invoice?->client_id ? $payment : null;
    }
}
