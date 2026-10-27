<?php

namespace Plugins\Stripe;

use App\Models\Invoice;
use App\Payments\PaymentModule;
use App\Plugins\Plugin;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Livewire\Component;

/** Card payments with Stripe Checkout: "Pay by card" on the client area's unpaid payment notices (StripeCheckouts). */
class StripePlugin extends Plugin implements PaymentModule
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
            ->withoutOverlapping()
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
