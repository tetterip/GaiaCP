<?php

namespace App\Filament\Resources\Clients\Actions;

use App\Credit\ApplyCredit;
use App\Credit\ClientCredit;
use App\Enums\CreditType;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Models\Client;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use RuntimeException;

/**
 * The client page's Credit menu: add or take off credit by hand (an overpayment, a refund, a goodwill gesture), with
 * a reason that the client sees in their history. For admins with billing.
 */
class ClientCreditActions
{
    public static function make(): ActionGroup
    {
        return ActionGroup::make([self::adjust(add: true), self::adjust(add: false)])
            ->label('Credit')
            ->icon(Heroicon::OutlinedWallet)
            ->button()
            ->color('gray')
            ->visible(fn () => InvoiceResource::canAccess());
    }

    private static function adjust(bool $add): Action
    {
        return Action::make($add ? 'addCredit' : 'removeCredit')
            ->label($add ? 'Add credit' : 'Take off credit')
            ->icon($add ? Heroicon::OutlinedPlusCircle : Heroicon::OutlinedMinusCircle)
            ->visible(fn (Client $record) => $add || $record->credit_cents > 0)
            ->modalDescription(fn (Client $record) => __('Balance now: :balance.', ['balance' => Money::format((int) $record->credit_cents, $record->currencyCode())]))
            ->schema(fn (Client $record) => [
                TextInput::make('amount')
                    ->required()
                    ->prefix($record->currencyCode())
                    ->rules(['regex:/^\d+([.,]\d{1,2})?$/']),
                TextInput::make('reason')
                    ->required()
                    ->maxLength(255)
                    ->helperText(__('The client sees it in their credit history.')),
                Toggle::make('apply')
                    ->label('Pay unpaid payment notices from it now')
                    ->default(true)
                    ->visible($add),
            ])
            ->action(function (Client $record, array $data, $livewire) use ($add) {
                $cents = Money::toCents($data['amount']);

                try {
                    if ($cents === 0) {
                        throw new RuntimeException(__('Enter an amount.'));
                    }

                    app(ClientCredit::class)->record($record, CreditType::Adjustment, $add ? $cents : -$cents, $data['reason']);
                } catch (RuntimeException $e) {
                    Notification::make()->title(__('Not saved'))->body($e->getMessage())->danger()->send();

                    return;
                }

                $paid = ($add && ($data['apply'] ?? false)) ? app(ApplyCredit::class)->forClient($record) : 0;

                Notification::make()
                    ->title(__('Credit balance: :balance', ['balance' => Money::format((int) $record->fresh()->credit_cents, $record->currencyCode())]))
                    ->body($paid > 0 ? __(':amount of it paid unpaid payment notices.', ['amount' => Money::format($paid, $record->currencyCode())]) : null)
                    ->success()
                    ->send();

                $livewire->dispatch('$refresh');
            });
    }
}
