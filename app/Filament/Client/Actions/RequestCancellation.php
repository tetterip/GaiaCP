<?php

namespace App\Filament\Client\Actions;

use App\Actions\CancellationRequests;
use App\Enums\CancellationType;
use App\Models\CancellationRequest;
use App\Models\Service;
use App\Providers\AppServiceProvider;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/**
 * The client's "Request cancellation" on a service, as in WHMCS: a reason, immediately or at the end of the billing
 * period, and the domain of the same name not to renew. An admin approves it (Services → Cancellation requests);
 * until then the client can withdraw it.
 */
class RequestCancellation
{
    /** @return list<Action> */
    public static function actions(): array
    {
        return [self::request(), self::withdraw()];
    }

    public static function pending(Service $record): ?CancellationRequest
    {
        return $record->cancellationRequests()->pending()->latest('id')->first();
    }

    private static function request(): Action
    {
        return Action::make('requestCancellation')
            ->label('Request cancellation')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->outlined()
            ->visible(fn (Service $record) => CancellationRequests::canRequest($record))
            ->modalHeading(fn (Service $record) => __('Cancel :service', ['service' => $record->domain ?: $record->label]))
            ->modalDescription(__('We will confirm by email once we have carried it out. Until then you can withdraw it from this page.'))
            ->schema(fn (Service $record) => array_values(array_filter([
                Textarea::make('reason')
                    ->label('Why are you cancelling?')
                    ->rows(3)
                    ->maxLength(2000),
                Radio::make('type')
                    ->label('When')
                    ->options([
                        CancellationType::EndOfPeriod->value => __('At the end of the billing period (:date)', [
                            'date' => $record->next_due_date?->format(AppServiceProvider::dateFormat()),
                        ]),
                        CancellationType::Immediate->value => __('Immediately (the service and its data are deleted)'),
                    ])
                    ->default(CancellationType::EndOfPeriod->value)
                    ->required(),
                ($domain = CancellationRequests::domainOf($record)) ? Toggle::make('with_domain')
                    ->label(__('Do not renew the domain :domain either', ['domain' => $domain->domain]))
                    ->helperText(__('It renews on :date for :amount. If you leave this off, you keep the domain.', [
                        'date' => $domain->next_due_date?->format(AppServiceProvider::dateFormat()),
                        'amount' => Money::format($domain->price_cents, $domain->currency),
                    ])) : null,
            ])))
            ->modalSubmitActionLabel(__('Send request'))
            ->action(function (Service $record, array $data) {
                app(CancellationRequests::class)->request(
                    $record,
                    CancellationType::from($data['type']),
                    $data['reason'] ?? null,
                    (bool) ($data['with_domain'] ?? false),
                );

                Notification::make()->title(__('Cancellation request sent'))->success()->send();
            });
    }

    private static function withdraw(): Action
    {
        return Action::make('withdrawCancellation')
            ->label('Withdraw cancellation request')
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('gray')
            ->visible(fn (Service $record) => self::pending($record) !== null)
            ->requiresConfirmation()
            ->modalDescription(__('The service continues as before.'))
            ->action(function (Service $record) {
                if ($request = self::pending($record)) {
                    app(CancellationRequests::class)->withdraw($request);
                }

                Notification::make()->title(__('Cancellation request withdrawn'))->success()->send();
            });
    }
}
