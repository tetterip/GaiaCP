<?php

namespace Plugins\NexiXpay;

use App\Models\Invoice;
use App\Payments\PaymentModule;
use App\Plugins\Plugin;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Livewire\Component;

/** Card payments through Nexi XPay (NPG API): "Pay by card (Nexi)" on the client area's unpaid payment notices. */
class NexiXpayPlugin extends Plugin implements PaymentModule
{
    /** A name, the same in every language. */
    public const NAME = 'Nexi XPay';

    public function id(): string
    {
        return 'nexi-xpay';
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function description(): string
    {
        return __('Clients pay their payment notices by card in the client area, on Nexi\'s own page (Nexi XPay, also for Alpha Bank merchants since 2022).');
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
        return 'nexixpay';
    }

    public function label(): string
    {
        return __('Card (Nexi)');
    }

    public function instructions(): ?string
    {
        return NexiXpayApi::configured() ? __('Pay by card in our client area: open the payment notice and press Pay by card.') : null;
    }

    public function paysOnline(): bool
    {
        return true;
    }

    public function settingsPage(): ?string
    {
        return NexiXpaySettings::class;
    }

    public function pages(): array
    {
        return [NexiXpaySettings::class];
    }

    public function settingKeys(): array
    {
        return ['nexi_xpay.mode', 'nexi_xpay.test_api_key', 'nexi_xpay.live_api_key'];
    }

    public function encryptedSettingKeys(): array
    {
        return ['nexi_xpay.test_api_key', 'nexi_xpay.live_api_key'];
    }

    public function moduleLogs(): array
    {
        return ['nexi-xpay' => self::NAME];
    }

    public function schedule(Schedule $schedule): void
    {
        $schedule->call(function () {
            $payments = app(NexiXpayPayments::class);
            $payments->flagStuck();

            if (NexiXpayApi::configured()) {
                $payments->recheck();
            }
        })
            ->name('nexi-xpay:recheck-payments')
            ->hourlyAt(45)
            ->withoutOverlapping(55)
            ->onOneServer();
    }

    public function clientRoutes(): void
    {
        Route::get('nexi-xpay/return', [NexiXpayController::class, 'return'])->name('nexi-xpay.return');
    }

    public function webhookRoutes(): void
    {
        Route::post('nexi-xpay', [NexiXpayController::class, 'notification'])->name('nexi-xpay');
    }

    public function clientPaymentNoticeActions(): array
    {
        if (! NexiXpayApi::configured()) {
            return [];
        }

        return [
            Action::make('payWithNexiXpay')
                ->label(__('Pay by card (Nexi)'))
                ->icon(Heroicon::OutlinedCreditCard)
                ->visible(fn (Invoice $record) => app(NexiXpayPayments::class)->canPay($record))
                ->action(function (Invoice $record, Component $livewire) {
                    try {
                        $url = app(NexiXpayPayments::class)->start($record);
                    } catch (NexiXpayException $e) {
                        report($e);
                        Notification::make()->danger()->title(__('Paying by card is not available right now. Please try again later.'))->send();

                        return;
                    }

                    $livewire->redirect($url);
                }),
        ];
    }
}
