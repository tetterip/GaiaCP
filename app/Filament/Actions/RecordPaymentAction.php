<?php

namespace App\Filament\Actions;

use App\Actions\DomainLifecycle;
use App\Actions\RecordPayment;
use App\Enums\ServiceStatus;
use App\Models\Service;
use App\Payments\PaymentMethods;
use App\Providers\AppServiceProvider;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

class RecordPaymentAction
{
    /** For what is owed: an unpaid first payment, an unpaid notice, or a due date that has come or is near. */
    public static function make(): Action
    {
        return self::base('recordPayment')
            ->label('Record payment')
            ->icon(Heroicon::OutlinedBanknotes)
            ->color('success')
            // A pending item already paid (its order's notice, say) waits for set up, not for another cycle's payment.
            ->visible(fn (Service $record) => ($record->status === ServiceStatus::Pending && ! $record->payments()->exists())
                || (self::renewable($record) && self::due($record)));
    }

    /** A client who pays the next cycle before anything is owed (in the service page's Price menu). */
    public static function early(): Action
    {
        return self::base('recordEarlyPayment')
            ->label('Record early payment')
            ->icon(Heroicon::OutlinedBanknotes)
            ->visible(fn (Service $record) => self::renewable($record) && ! self::due($record));
    }

    private static function renewable(Service $record): bool
    {
        return in_array($record->status, [ServiceStatus::Active, ServiceStatus::Suspended], true);
    }

    /** It has an unpaid notice line, or its due date falls within the days a notice is issued ahead (`invoices.days_before`). */
    public static function due(Service $record): bool
    {
        return ! $record->next_due_date->isAfter(today()->addDays((int) config('gaiacp.invoices.days_before', 14)))
            || $record->invoiceItems()->whereNull('payment_id')->whereHas('invoice', fn ($q) => $q->unpaid())->exists();
    }

    private static function base(string $name): Action
    {
        return Action::make($name)
            ->modalDescription(fn (Service $record) => __('Covers one billing cycle (:cycle): the due date moves from :from to :to.', [
                'cycle' => mb_strtolower($record->billing_cycle->getLabel()),
                'from' => $record->next_due_date->format(AppServiceProvider::dateFormat()),
                'to' => $record->billing_cycle->advance($record->next_due_date, $record->anchorDay())->format(AppServiceProvider::dateFormat()),
            ]).(app(DomainLifecycle::class)->shouldRenewOnPayment($record)
                ? ' '.__('It also renews :domain at the registrar for :years year(s), which charges your registrar account.', ['domain' => $record->domain, 'years' => $record->registrationYears()])
                : ''))
            ->schema([
                TextInput::make('amount')
                    ->required()
                    ->prefix(fn (Service $record) => $record->currency)
                    ->rules(['regex:/^\d+([.,]\d{1,2})?$/'])
                    ->default(fn (Service $record) => Money::toDecimal($record->price_cents)),
                DatePicker::make('paid_on')
                    ->required()
                    ->default(now())
                    ->maxDate(now()),
                Select::make('method')
                    ->options(fn () => PaymentMethods::options())
                    ->default(fn (Service $record) => array_key_exists((string) $record->client->default_payment_method, PaymentMethods::options()) ? $record->client->default_payment_method : PaymentMethods::default())
                    ->required(),
                TextInput::make('reference')
                    ->maxLength(255)
                    ->helperText(__('Bank transaction reference, receipt number…')),
                Textarea::make('notes')
                    ->rows(2),
            ])
            ->action(function (Service $record, array $data, $livewire) {
                $recorder = app(RecordPayment::class);
                $recorder->handle(
                    $record,
                    Money::toCents($data['amount']),
                    CarbonImmutable::parse($data['paid_on']),
                    $data['method'],
                    $data['reference'] ?? null,
                    $data['notes'] ?? null,
                );

                $record->refresh();

                Notification::make()
                    ->title(__('Payment recorded'))
                    ->body(__('Next due date: :date', ['date' => $record->next_due_date->format(AppServiceProvider::dateFormat())]))
                    ->success()
                    ->send();

                if ($recorder->renewalError !== null) {
                    Notification::make()
                        ->title(__('The payment is recorded, but the domain was not renewed at the registrar'))
                        ->body($recorder->renewalError.' '.__('Renew it at the registrar by hand.'))
                        ->danger()
                        ->persistent()
                        ->send();
                }

                if ($recorder->setupError !== null) {
                    Notification::make()
                        ->title(__('The payment is recorded, but the service was not set up'))
                        ->body($recorder->setupError.' '.__('Retry from the Account or Registrar menu.'))
                        ->danger()
                        ->persistent()
                        ->send();
                }

                if (method_exists($livewire, 'refreshFormData')) {
                    $livewire->refreshFormData(['next_due_date', 'status', 'suspension_reason']);
                }
            });
    }
}
