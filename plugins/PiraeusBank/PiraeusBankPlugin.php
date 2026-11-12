<?php

namespace Plugins\PiraeusBank;

use App\Models\Invoice;
use App\Payments\PaymentModule;
use App\Plugins\Plugin;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Livewire\Component;

/** Card and IRIS payments through Piraeus Bank's paycenter: "Pay by card (Piraeus Bank)" on unpaid payment notices. */
class PiraeusBankPlugin extends Plugin implements PaymentModule
{
    /** A name, the same in every language. */
    public const NAME = 'Piraeus Bank';

    public function id(): string
    {
        return 'piraeusbank';
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function description(): string
    {
        return __('Clients pay their payment notices by card or IRIS in the client area, on Piraeus Bank\'s own page (epay paycenter).');
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
        return 'piraeusbank';
    }

    public function label(): string
    {
        return __('Card (Piraeus Bank)');
    }

    public function instructions(): ?string
    {
        return PiraeusBankPayments::configured() ? __('Pay by card in our client area: open the payment notice and press Pay by card.') : null;
    }

    public function paysOnline(): bool
    {
        return true;
    }

    public function settingsPage(): ?string
    {
        return PiraeusBankSettings::class;
    }

    public function pages(): array
    {
        return [PiraeusBankSettings::class];
    }

    public function settingKeys(): array
    {
        return ['piraeusbank.merchant_id', 'piraeusbank.acquirer_id', 'piraeusbank.pos_id', 'piraeusbank.username', 'piraeusbank.password'];
    }

    public function encryptedSettingKeys(): array
    {
        return ['piraeusbank.password'];
    }

    public function moduleLogs(): array
    {
        return ['piraeusbank' => self::NAME];
    }

    public function schedule(Schedule $schedule): void
    {
        $schedule->call(function () {
            $payments = app(PiraeusBankPayments::class);
            $payments->flagStuck();
            $payments->expire();
        })
            ->name('piraeusbank:close-unanswered')
            ->hourlyAt(40)
            ->withoutOverlapping(55)
            ->onOneServer();
    }

    public function clientRoutes(): void
    {
        Route::get('piraeusbank/pay/{reference}', [PiraeusBankController::class, 'pay'])->name('piraeusbank.pay');
        Route::get('piraeusbank/back/{reference}', [PiraeusBankController::class, 'back'])->name('piraeusbank.back');
    }

    public function webhookRoutes(): void
    {
        Route::match(['get', 'post'], 'piraeusbank/{result}', [PiraeusBankController::class, 'answer'])->whereIn('result', ['success', 'fail', 'cancel'])->name('piraeusbank');
    }

    public function clientPaymentNoticeActions(): array
    {
        if (! PiraeusBankPayments::configured()) {
            return [];
        }

        return [
            Action::make('payWithPiraeusBank')
                ->label(__('Pay by card (Piraeus Bank)'))
                ->icon(Heroicon::OutlinedCreditCard)
                ->visible(fn (Invoice $record) => app(PiraeusBankPayments::class)->canPay($record))
                ->action(function (Invoice $record, Component $livewire) {
                    try {
                        $payment = app(PiraeusBankPayments::class)->start($record);
                    } catch (PiraeusBankException $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();

                        return;
                    }

                    $livewire->redirect(route('filament.client.piraeusbank.pay', ['reference' => $payment->reference]));
                }),
        ];
    }
}
