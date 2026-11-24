<?php

namespace Plugins\Stripe;

use App\Models\Invoice;
use App\Models\Payment;
use App\Payments\PaymentModule;
use App\Payments\RefundsAtProvider;
use App\Plugins\Plugin;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Livewire\Component;

/**
 * Card payments with Stripe Checkout: "Pay by card" on the client area's unpaid payment notices (StripeCheckouts).
 * Refund payment can give one back at Stripe; refunds and chargebacks made at Stripe ring the bell (StripeReports).
 */
class StripePlugin extends Plugin implements PaymentModule, RefundsAtProvider
{
    public function id(): string
    {
        return 'stripe';
    }

    public function name(): string
    {
        return __('Stripe');
    }

    public function description(): string
    {
        return __('Clients pay their payment notices by card in the client area, on Stripe\'s own payment page.');
    }

    public function category(): string
    {
        return 'Payments';
    }

    public function key(): string
    {
        return 'stripe';
    }

    public function label(): string
    {
        return __('Card (Stripe)');
    }

    public function instructions(): ?string
    {
        return StripeApi::configured() ? __('Pay by card in our client area: open the payment notice and press Pay by card.') : null;
    }

    public function paysOnline(): bool
    {
        return true;
    }

    /** A payment of a checkout GaiaCP opened, in the mode in use now (each mode's key only reaches its own payments). */
    public function canRefundAtProvider(Payment $payment): bool
    {
        return $payment->method === 'stripe' && StripeApi::configured() && str_starts_with((string) $payment->reference, 'pi_')
            && StripeCheckout::query()->where('payment_intent', $payment->reference)->where('livemode', StripeApi::mode() === 'live')->exists();
    }

    public function refundAtProvider(Payment $payment): string
    {
        $refund = app(StripeApi::class)->refund(
            (string) $payment->reference,
            StripeCheckouts::stripeAmount($payment->amount_cents, $payment->currency),
            'gaiacp-refund-payment-'.$payment->id,
            ['payment_id' => $payment->id],
        );

        if (blank($refund['id'] ?? null) || in_array($refund['status'] ?? null, ['failed', 'canceled'], true)) {
            throw new StripeException(__('Stripe did not give the money back: :status', ['status' => $refund['failure_reason'] ?? $refund['status'] ?? 'no refund']));
        }

        return (string) $refund['id'];
    }

    public function settingsPage(): ?string
    {
        return StripeSettings::class;
    }

    public function pages(): array
    {
        return [StripeSettings::class];
    }

    public function settingKeys(): array
    {
        return ['stripe.mode', 'stripe.test_secret_key', 'stripe.test_webhook_secret', 'stripe.live_secret_key', 'stripe.live_webhook_secret'];
    }

    public function encryptedSettingKeys(): array
    {
        return ['stripe.test_secret_key', 'stripe.test_webhook_secret', 'stripe.live_secret_key', 'stripe.live_webhook_secret'];
    }

    public function moduleLogs(): array
    {
        return ['stripe' => 'Stripe'];
    }

    public function schedule(Schedule $schedule): void
    {
        $schedule->call(fn () => app(StripeCheckouts::class)->flagStuck())
            ->name('stripe:flag-stuck-checkouts')
            ->hourly()
            ->withoutOverlapping(55)
            ->onOneServer();

        $schedule->call(fn () => StripeApi::configured() ? app(StripeCheckouts::class)->recheck() : 0)
            ->name('stripe:recheck-checkouts')
            ->hourlyAt(20)
            ->withoutOverlapping(55)
            ->onOneServer();
    }

    public function clientRoutes(): void
    {
        Route::get('stripe/return', [StripeController::class, 'return'])->name('stripe.return');
    }

    public function webhookRoutes(): void
    {
        Route::post('stripe', [StripeController::class, 'webhook'])->name('stripe');
    }

    public function clientPaymentNoticeActions(): array
    {
        if (! StripeApi::configured()) {
            return [];
        }

        return [
            Action::make('payByCard')
                ->label('Pay by card')
                ->icon(Heroicon::OutlinedCreditCard)
                ->visible(fn (Invoice $record) => app(StripeCheckouts::class)->canPay($record))
                ->action(function (Invoice $record, Component $livewire) {
                    try {
                        $url = app(StripeCheckouts::class)->start($record);
                    } catch (StripeException $e) {
                        report($e);
                        Notification::make()->danger()->title(__('Paying by card is not available right now. Please try again later.'))->send();

                        return;
                    }

                    $livewire->redirect($url);
                }),
        ];
    }
}
