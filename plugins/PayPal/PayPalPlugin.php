<?php

namespace Plugins\PayPal;

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
 * Payments with PayPal: "Pay with PayPal" on the client area's unpaid payment notices (PayPalPayments). Refund payment
 * can give one back at PayPal; refunds and disputes made at PayPal ring the bell (PayPalReports).
 */
class PayPalPlugin extends Plugin implements PaymentModule, RefundsAtProvider
{
    public function id(): string
    {
        return 'paypal';
    }

    public function name(): string
    {
        return 'PayPal';
    }

    public function description(): string
    {
        return __('Clients pay their payment notices with PayPal (their PayPal balance or a card) in the client area, on PayPal\'s own page.');
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
        return 'paypal';
    }

    public function label(): string
    {
        return 'PayPal';
    }

    public function instructions(): ?string
    {
        return PayPalApi::configured() ? __('Pay with PayPal in our client area: open the payment notice and press Pay with PayPal.') : null;
    }

    public function paysOnline(): bool
    {
        return true;
    }

    /** A capture of an order GaiaCP opened, in the mode in use now (each mode's keys only reach its own payments). */
    public function canRefundAtProvider(Payment $payment): bool
    {
        return $payment->method === 'paypal' && PayPalApi::configured() && filled($payment->reference)
            && PayPalOrder::query()->where('capture_id', $payment->reference)->where('live', PayPalApi::mode() === 'live')->exists();
    }

    public function refundAtProvider(Payment $payment): string
    {
        $refund = app(PayPalApi::class)->refund((string) $payment->reference, PayPalPayments::value($payment->amount_cents), strtoupper($payment->currency), 'gaiacp-refund-payment-'.$payment->id);

        if (blank($refund['id'] ?? null) || in_array($refund['status'] ?? null, ['CANCELLED', 'FAILED'], true)) {
            throw new PayPalException(__('PayPal did not give the money back: :status', ['status' => $refund['status'] ?? 'no refund']));
        }

        return (string) $refund['id'];
    }

    public function settingsPage(): ?string
    {
        return PayPalSettings::class;
    }

    public function pages(): array
    {
        return [PayPalSettings::class];
    }

    public function settingKeys(): array
    {
        return ['paypal.mode', 'paypal.sandbox_client_id', 'paypal.sandbox_secret', 'paypal.live_client_id', 'paypal.live_secret'];
    }

    public function encryptedSettingKeys(): array
    {
        return ['paypal.sandbox_secret', 'paypal.live_secret'];
    }

    public function moduleLogs(): array
    {
        return ['paypal' => 'PayPal'];
    }

    public function schedule(Schedule $schedule): void
    {
        $schedule->call(fn () => app(PayPalPayments::class)->flagStuck())
            ->name('paypal:flag-stuck-orders')
            ->hourly()
            ->withoutOverlapping(55)
            ->onOneServer();

        $schedule->call(fn () => PayPalApi::configured() ? app(PayPalPayments::class)->recheck() : 0)
            ->name('paypal:recheck-orders')
            ->hourlyAt(25)
            ->withoutOverlapping(55)
            ->onOneServer();
    }

    public function clientRoutes(): void
    {
        Route::get('paypal/return', [PayPalController::class, 'return'])->name('paypal.return');
    }

    public function webhookRoutes(): void
    {
        Route::post('paypal', [PayPalController::class, 'webhook'])->name('paypal');
    }

    public function clientPaymentNoticeActions(): array
    {
        if (! PayPalApi::configured()) {
            return [];
        }

        return [
            Action::make('payWithPaypal')
                ->label('Pay with PayPal')
                ->icon(Heroicon::OutlinedBanknotes)
                ->visible(fn (Invoice $record) => app(PayPalPayments::class)->canPay($record))
                ->action(function (Invoice $record, Component $livewire) {
                    try {
                        $url = app(PayPalPayments::class)->start($record);
                    } catch (PayPalException $e) {
                        report($e);
                        Notification::make()->danger()->title(__('Paying with PayPal is not available right now. Please try again later.'))->send();

                        return;
                    }

                    $livewire->redirect($url);
                }),
        ];
    }
}
