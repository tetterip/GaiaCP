<?php

namespace Plugins\MastercardGateway;

use App\Models\Invoice;
use App\Payments\PaymentModule;
use App\Plugins\Plugin;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Livewire\Component;

/** Card payments through Mastercard Gateway's hosted checkout: "Pay by card" on the client area's unpaid payment notices. */
class MastercardGatewayPlugin extends Plugin implements PaymentModule
{
    /** A name, the same in every language. */
    public const NAME = 'Mastercard Gateway';

    public function id(): string
    {
        return 'mastercard';
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function description(): string
    {
        return __('Clients pay their payment notices by card in the client area, on the payment page of Mastercard Gateway (MPGS), which many banks offer their merchants.');
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
        return 'mastercard';
    }

    public function label(): string
    {
        return __('Card (:name)', ['name' => MastercardApi::displayName()]);
    }

    public function instructions(): ?string
    {
        return MastercardApi::configured() ? __('Pay by card in our client area: open the payment notice and press Pay by card.') : null;
    }

    public function paysOnline(): bool
    {
        return true;
    }

    public function settingsPage(): ?string
    {
        return MastercardSettings::class;
    }

    public function pages(): array
    {
        return [MastercardSettings::class];
    }

    public function settingKeys(): array
    {
        return ['mastercard.display_name', 'mastercard.host', 'mastercard.custom_host', 'mastercard.mode', 'mastercard.test_merchant_id', 'mastercard.test_api_password', 'mastercard.live_merchant_id', 'mastercard.live_api_password'];
    }

    public function encryptedSettingKeys(): array
    {
        return ['mastercard.test_api_password', 'mastercard.live_api_password'];
    }

    public function moduleLogs(): array
    {
        return ['mastercard' => self::NAME];
    }

    public function schedule(Schedule $schedule): void
    {
        $schedule->call(function () {
            $payments = app(MastercardPayments::class);
            $payments->flagStuck();

            if (MastercardApi::configured()) {
                $payments->recheck();
            }
        })
            ->name('mastercard:recheck-payments')
            ->hourlyAt(50)
            ->withoutOverlapping(55)
            ->onOneServer();
    }

    public function clientRoutes(): void
    {
        Route::get('mastercard/pay/{order}', [MastercardController::class, 'pay'])->name('mastercard.pay');
        Route::get('mastercard/return', [MastercardController::class, 'return'])->name('mastercard.return');
    }

    public function clientPaymentNoticeActions(): array
    {
        if (! MastercardApi::configured()) {
            return [];
        }

        return [
            Action::make('payWithMastercard')
                ->label(__('Pay by card (:name)', ['name' => MastercardApi::displayName()]))
                ->icon(Heroicon::OutlinedCreditCard)
                ->visible(fn (Invoice $record) => app(MastercardPayments::class)->canPay($record))
                ->action(function (Invoice $record, Component $livewire) {
                    try {
                        $payment = app(MastercardPayments::class)->start($record);
                    } catch (MastercardException $e) {
                        report($e);
                        Notification::make()->danger()->title(__('Paying by card is not available right now. Please try again later.'))->send();

                        return;
                    }

                    $livewire->redirect(route('filament.client.mastercard.pay', ['order' => $payment->order_id]));
                }),
        ];
    }
}
