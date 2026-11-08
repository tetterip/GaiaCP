<?php

namespace App\Filament\Actions;

use App\Actions\DomainLifecycle;
use App\Actions\RecordServicePayment;
use App\Enums\ServiceStatus;
use App\Models\Service;
use App\Payments\PaymentMethods;
use App\Providers\AppServiceProvider;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;

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
            ->visible(fn (Service $record) => ($record->status === ServiceStatus::Pending && ! self::paid($record))
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
            || (array_key_exists('unpaid_line_exists', $record->getAttributes())
                ? (bool) $record->getAttribute('unpaid_line_exists')
                : $record->invoiceItems()->whereNull('payment_id')->whereHas('invoice', fn ($q) => $q->unpaid())->exists());
    }

    /**
     * What a services table loads with its rows, so the action's checks need no query per row (paid(), due()).
     *
     * @param  Builder<Service>  $query
     * @return Builder<Service>
     */
    public static function withChecks(Builder $query): Builder
    {
        return $query->withExists([
            'payments',
            'invoiceItems as unpaid_line_exists' => fn ($q) => $q->whereNull('payment_id')->whereHas('invoice', fn ($q) => $q->unpaid()),
        ]);
    }

    /** It has a payment (payments_exists when the table loaded it). */
    public static function paid(Service $record): bool
    {
        return array_key_exists('payments_exists', $record->getAttributes())
            ? (bool) $record->getAttribute('payments_exists')
            : $record->payments()->exists();
    }

    private static function base(string $name): Action
    {
        return Action::make($name)
            ->modalDescription(fn (Service $record) => __('Covers one billing cycle (:cycle): the due date moves from :from to :to.', [
                'cycle' => mb_strtolower($record->billing_cycle->getLabel()),
                'from' => $record->next_due_date->format(AppServiceProvider::dateFormat()),
                'to' => $record->billing_cycle->advance($record->next_due_date, $record->anchorDay())->format(AppServiceProvider::dateFormat()),
            ]).(app(DomainLifecycle::class)->shouldRenewOnPayment($record)
                ? ' '.trans_choice('It also renews :domain at the registrar for :count year, which charges your registrar account.|It also renews :domain at the registrar for :count years, which charges your registrar account.', $record->registrationYears(), ['domain' => $record->domain])
                : ''))
            ->schema([
                // What its payment notice asks for the cycle (with tax), so a payment of the notice's amount needs no typing
                TextInput::make('amount')
                    ->required()
                    ->prefix(fn (Service $record) => $record->currency)
                    ->rules(['regex:/^\d+([.,]\d{1,2})?$/'])
                    ->default(fn (Service $record) => Money::toDecimal(RecordServicePayment::suggested($record)))
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn (Service $record, $state, Set $set) => $set('difference', RecordServicePayment::defaultOption($record, self::cents($state)))),
                Radio::make('difference')
                    ->label(fn (Service $record, Get $get) => __('The payment notice asks for :amount. The difference:', [
                        'amount' => Money::format((int) RecordServicePayment::expected($record), $record->currency),
                    ]))
                    ->options(fn (Service $record, Get $get) => RecordServicePayment::options($record, self::cents($get('amount'))))
                    ->visible(fn (Service $record, Get $get) => RecordServicePayment::options($record, self::cents($get('amount'))) !== [])
                    ->required(fn (Service $record, Get $get) => RecordServicePayment::options($record, self::cents($get('amount'))) !== []),
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
                $service = app(RecordServicePayment::class);
                $recorder = $service->recorder;
                $service->handle(
                    $record,
                    Money::toCents($data['amount']),
                    CarbonImmutable::parse($data['paid_on']),
                    $data['method'],
                    $data['reference'] ?? null,
                    $data['notes'] ?? null,
                    $data['difference'] ?? null,
                );

                $record->refresh();

                Notification::make()
                    ->title($service->payment ? __('Payment recorded') : __('Put in the client\'s balance'))
                    ->body(collect([
                        __('Next due date: :date', ['date' => $record->next_due_date->format(AppServiceProvider::dateFormat())]),
                        $service->credited > 0 ? __(':amount went into the client\'s balance.', ['amount' => Money::format($service->credited, $record->currency)]) : null,
                    ])->filter()->join(' '))
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

    private static function cents(mixed $amount): int
    {
        return is_string($amount) && preg_match('/^\d+([.,]\d{1,2})?$/', $amount) ? Money::toCents($amount) : -1;
    }
}
