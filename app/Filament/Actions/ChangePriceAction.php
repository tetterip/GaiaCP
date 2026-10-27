<?php

namespace App\Filament\Actions;

use App\Actions\ChangePrice;
use App\Enums\ServiceType;
use App\Models\Product;
use App\Models\Service;
use App\Models\Tld;
use App\Providers\AppServiceProvider;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;

/**
 * "Change price" (App\Actions\ChangePrice): on a service, on the services picked in a list, and on a product or a TLD
 * for all its services (at the price it has now, so edit its prices first).
 */
class ChangePriceAction
{
    /** On the service's page. */
    public static function make(): Action
    {
        return Action::make('changePrice')
            ->label('Change price')
            ->icon(Heroicon::OutlinedCurrencyEuro)
            ->color('gray')
            ->visible(fn (Service $record) => ChangePrice::changeable($record))
            ->modalDescription(fn (Service $record) => __('Now :price :cycle.', ['price' => Money::format($record->price_cents, $record->currency), 'cycle' => $record->billing_cycle->getLabel()])
                .($record->new_price_from ? ' '.self::scheduled($record) : '').' '.self::explained())
            ->schema(self::schema())
            ->action(fn (Service $record, array $data) => self::run(collect([$record]), $data));
    }

    public static function cancel(): Action
    {
        return Action::make('cancelPriceChange')
            ->label('Cancel price change')
            ->icon(Heroicon::OutlinedXMark)
            ->color('gray')
            ->visible(fn (Service $record) => $record->new_price_from !== null)
            ->requiresConfirmation()
            ->modalDescription(fn (Service $record) => self::scheduled($record).' '.__('The client is not emailed about the cancellation.'))
            ->action(function (Service $record) {
                app(ChangePrice::class)->cancel($record);
                Notification::make()->title(__('Price change cancelled'))->success()->send();
            });
    }

    /** On the services picked in a list. */
    public static function bulk(): BulkAction
    {
        return BulkAction::make('changePrice')
            ->label('Change price')
            ->icon(Heroicon::OutlinedCurrencyEuro)
            ->modalDescription(self::explained())
            ->schema(self::schema())
            ->deselectRecordsAfterCompletion()
            ->action(fn (Collection $records, array $data) => self::run($records, $data));
    }

    public static function forProduct(): Action
    {
        return Action::make('changeServicesPrice')
            ->label('Change its services\' price')
            ->icon(Heroicon::OutlinedCurrencyEuro)
            ->color('gray')
            ->modalDescription(fn (Product $record) => __('Its :count active service(s) get the product\'s price for their billing cycle as it is now. Edit the product\'s prices first.', ['count' => $record->services()->billable()->count()])
                .' '.self::explained())
            ->schema(self::schema(modes: false))
            ->action(fn (Product $record, array $data) => self::run($record->services()->billable()->get(), ['mode' => 'catalog'] + $data));
    }

    public static function forTld(): Action
    {
        return Action::make('changeDomainsPrice')
            ->label('Change its domains\' price')
            ->icon(Heroicon::OutlinedCurrencyEuro)
            ->color('gray')
            ->modalDescription(fn (Tld $record) => __('Its :count active domain(s) get its renewal price for their term as it is now. Edit its prices first. Domains only tracked here are left out.', ['count' => self::domainsOf($record)->count()])
                .' '.self::explained())
            ->schema(self::schema(modes: false))
            ->action(fn (Tld $record, array $data) => self::run(self::domainsOf($record), ['mode' => 'catalog'] + $data));
    }

    /** @return Collection<int, Service> the active domains whose TLD is $tld (longest match: x.com.gr is .com.gr's) */
    public static function domainsOf(Tld $tld): Collection
    {
        return Service::query()->billable()->where('type', ServiceType::Domain)->where('domain', 'like', '%'.$tld->tld)->with('domainDetails')->get()
            ->filter(fn (Service $service) => Tld::match($service->domain, activeOnly: false)?->is($tld))
            ->values();
    }

    /** @return list<mixed> */
    private static function schema(bool $modes = true): array
    {
        return array_values(array_filter([
            $modes ? Radio::make('mode')
                ->label('New price')
                ->options([
                    'catalog' => __('Its product\'s price (a domain: its TLD\'s renewal price)'),
                    'percent' => __('Raise by a percentage'),
                    'amount' => __('A set amount'),
                ])
                ->default('percent')
                ->required()
                ->live() : null,
            $modes ? TextInput::make('value')
                ->label(fn (Get $get) => $get('mode') === 'amount' ? __('Amount') : __('Percentage'))
                ->suffix(fn (Get $get) => $get('mode') === 'amount' ? null : '%')
                ->visible(fn (Get $get) => in_array($get('mode'), ['percent', 'amount'], true))
                ->required()
                ->rules(fn (Get $get) => $get('mode') === 'amount' ? ['regex:/^\d+([.,]\d{1,2})?$/'] : ['numeric', 'min:-90', 'max:500'])
                ->helperText(fn (Get $get) => $get('mode') === 'amount'
                    ? __('In each service\'s own currency.')
                    : __('Of each service\'s current price, rounded to the cent. A minus lowers it.')) : null,
            DatePicker::make('from')
                ->label('For renewals due from')
                ->default(fn () => ChangePrice::earliest()->toDateString())
                ->minDate(fn () => ChangePrice::earliest()->toDateString())
                ->required()
                ->helperText(fn () => __('At least :days days from today (Settings → Automation → Price change notice).', ['days' => (int) config('gaiacp.automation.price_change_notice_days', 30)])),
            Toggle::make('email')
                ->label('Email the clients')
                ->default(true)
                ->helperText(__('One Price Change Notice per client, listing their services with the current and the new price.')),
        ]));
    }

    /** @param  Collection<int, Service>  $services */
    private static function run(Collection $services, array $data): void
    {
        $value = isset($data['value']) ? str_replace(',', '.', (string) $data['value']) : null;
        $summary = app(ChangePrice::class)->schedule($services, $data['mode'], $value, CarbonImmutable::parse($data['from']), (bool) $data['email']);

        Notification::make()
            ->{$summary['scheduled'] > 0 ? 'success' : 'warning'}()
            ->title(__(':count service(s) get the new price from :date', ['count' => $summary['scheduled'], 'date' => CarbonImmutable::parse($data['from'])->format(AppServiceProvider::dateFormat())]))
            ->body(collect([
                $summary['skipped'] ? __(':count left as they are (same price, no price for their cycle, or ending).', ['count' => $summary['skipped']]) : null,
                $data['email'] ? __(':count client(s) emailed.', ['count' => $summary['emailed']]) : null,
            ])->filter()->join(' ') ?: null)
            ->send();
    }

    public static function scheduled(Service $service): string
    {
        return __('From :date: :price (scheduled).', [
            'date' => $service->new_price_from->format(AppServiceProvider::dateFormat()),
            'price' => Money::format((int) $service->new_price_cents, $service->currency),
        ]);
    }

    private static function explained(): string
    {
        return __('The first payment notice for a due date on or after the date bills the new price; notices already issued stay as they are.');
    }
}
