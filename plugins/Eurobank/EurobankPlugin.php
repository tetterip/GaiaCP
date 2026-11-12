<?php

namespace Plugins\Eurobank;

use App\Models\Invoice;
use App\Payments\PaymentModule;
use App\Plugins\Plugin;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Livewire\Component;

/** Card payments through Eurobank (Cardlink): "Pay by card (Eurobank)" on unpaid payment notices. */
class EurobankPlugin extends Plugin implements PaymentModule
{
    public function id(): string
    {
        return 'eurobank';
    }

    public function name(): string
    {
        return 'Eurobank';
    }

    public function description(): string
    {
        return __('Clients pay their payment notices by card in the client area, on Eurobank\'s own page (Cardlink).');
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
        return 'eurobank';
    }

    public function label(): string
    {
        return __('Card (Eurobank)');
    }

    public function instructions(): ?string
    {
        return EurobankPayments::configured() ? __('Pay by card in our client area: open the payment notice and press Pay by card.') : null;
    }

    public function paysOnline(): bool
    {
        return true;
    }

    public function settingsPage(): ?string
    {
        return EurobankSettings::class;
    }

    public function pages(): array
    {
        return [EurobankSettings::class];
    }

    public function settingKeys(): array
    {
        return ['eurobank.mode', 'eurobank.merchant_id', 'eurobank.shared_secret'];
    }

    public function encryptedSettingKeys(): array
    {
        return ['eurobank.shared_secret'];
    }

    public function schedule(Schedule $schedule): void
    {
        $schedule->call(function () {
            $payments = app(EurobankPayments::class);
            $payments->flagStuck();
            $payments->expire();
        })
            ->name('eurobank:close-unanswered')
            ->hourlyAt(35)
            ->withoutOverlapping(55)
            ->onOneServer();
    }

    public function clientRoutes(): void
    {
        Route::get('eurobank/pay/{reference}', [EurobankController::class, 'pay'])->name('eurobank.pay');
        Route::get('eurobank/back/{reference}', [EurobankController::class, 'back'])->name('eurobank.back');
    }

    public function webhookRoutes(): void
    {
        Route::post('eurobank/{result}', [EurobankController::class, 'answer'])->whereIn('result', ['success', 'failure'])->name('eurobank');
    }

    public function clientPaymentNoticeActions(): array
    {
        if (! EurobankPayments::configured()) {
            return [];
        }

        return [
            Action::make('payWithEurobank')
                ->label(__('Pay by card (Eurobank)'))
                ->icon(Heroicon::OutlinedCreditCard)
                ->visible(fn (Invoice $record) => app(EurobankPayments::class)->canPay($record))
                ->action(function (Invoice $record, Component $livewire) {
                    try {
                        $payment = app(EurobankPayments::class)->start($record);
                    } catch (EurobankException $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();

                        return;
                    }

                    $livewire->redirect(route('filament.client.eurobank.pay', ['reference' => $payment->reference]));
                }),
        ];
    }
}
