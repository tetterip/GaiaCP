<?php

namespace Plugins\AlphaBank;

use App\Models\Invoice;
use App\Payments\PaymentModule;
use App\Plugins\Plugin;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Livewire\Component;

/** Card payments through Alpha Bank e-Commerce (Cardlink): "Pay by card (Alpha Bank)" on unpaid payment notices. */
class AlphaBankPlugin extends Plugin implements PaymentModule
{
    /** A name, the same in every language. */
    public const NAME = 'Alpha Bank';

    public function id(): string
    {
        return 'alphabank';
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function description(): string
    {
        return __('Clients pay their payment notices by card in the client area, on Alpha Bank\'s own page (Alpha e-Commerce, Cardlink).');
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
        return 'alphabank';
    }

    public function label(): string
    {
        return __('Card (Alpha Bank)');
    }

    public function instructions(): ?string
    {
        return AlphaBankPayments::configured() ? __('Pay by card in our client area: open the payment notice and press Pay by card.') : null;
    }

    public function paysOnline(): bool
    {
        return true;
    }

    public function settingsPage(): ?string
    {
        return AlphaBankSettings::class;
    }

    public function pages(): array
    {
        return [AlphaBankSettings::class];
    }

    public function settingKeys(): array
    {
        return ['alphabank.mode', 'alphabank.merchant_id', 'alphabank.shared_secret'];
    }

    public function encryptedSettingKeys(): array
    {
        return ['alphabank.shared_secret'];
    }

    public function schedule(Schedule $schedule): void
    {
        $schedule->call(function () {
            $payments = app(AlphaBankPayments::class);
            $payments->flagStuck();
            $payments->expire();
        })
            ->name('alphabank:close-unanswered')
            ->hourlyAt(35)
            ->withoutOverlapping(55)
            ->onOneServer();
    }

    public function clientRoutes(): void
    {
        Route::get('alphabank/pay/{reference}', [AlphaBankController::class, 'pay'])->name('alphabank.pay');
        Route::get('alphabank/back/{reference}', [AlphaBankController::class, 'back'])->name('alphabank.back');
    }

    public function webhookRoutes(): void
    {
        Route::post('alphabank/{result}', [AlphaBankController::class, 'answer'])->whereIn('result', ['success', 'failure'])->name('alphabank');
    }

    public function clientPaymentNoticeActions(): array
    {
        if (! AlphaBankPayments::configured()) {
            return [];
        }

        return [
            Action::make('payWithAlphaBank')
                ->label(__('Pay by card (Alpha Bank)'))
                ->icon(Heroicon::OutlinedCreditCard)
                ->visible(fn (Invoice $record) => app(AlphaBankPayments::class)->canPay($record))
                ->action(function (Invoice $record, Component $livewire) {
                    try {
                        $payment = app(AlphaBankPayments::class)->start($record);
                    } catch (AlphaBankException $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();

                        return;
                    }

                    $livewire->redirect(route('filament.client.alphabank.pay', ['reference' => $payment->reference]));
                }),
        ];
    }
}
