<?php

namespace App\Filament\Client\Actions;

use App\Actions\ChangeProduct;
use App\Actions\UpgradeRequests;
use App\Models\Product;
use App\Models\Service;
use App\Models\UpgradeRequest;
use App\Providers\AppServiceProvider;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/**
 * The client's "Upgrade / downgrade" on a service: another package clients can order, with its price for the
 * service's cycle (options it keeps included). An admin approves it (Services → Upgrade requests); until then the
 * client can withdraw it.
 */
class RequestUpgrade
{
    /** @return list<Action> */
    public static function actions(): array
    {
        return [self::request(), self::withdraw()];
    }

    public static function pending(Service $record): ?UpgradeRequest
    {
        return $record->upgradeRequests()->pending()->with('product')->latest('id')->first();
    }

    private static function request(): Action
    {
        return Action::make('requestUpgrade')
            ->label('Upgrade / downgrade')
            ->icon(Heroicon::OutlinedArrowsUpDown)
            ->color('primary')
            ->outlined()
            ->visible(fn (Service $record) => UpgradeRequests::canRequest($record))
            ->modalHeading(fn (Service $record) => __('Change the package of :service', ['service' => $record->domain ?: $record->label]))
            ->modalDescription(fn (Service $record) => __('Now :product at :price :cycle. Once we approve it, the new package applies at once and the new price from your next due date (:date).', [
                'product' => $record->product?->name ?? $record->label,
                'price' => Money::format($record->price_cents, $record->currency),
                'cycle' => $record->billing_cycle->getLabel(),
                'date' => $record->next_due_date->format(AppServiceProvider::dateFormat()),
            ]))
            ->schema(fn (Service $record) => [
                Radio::make('product_id')
                    ->label('New package')
                    ->options(fn () => UpgradeRequests::products($record)->orderBy('name')->get()
                        ->mapWithKeys(fn (Product $product) => ($price = ChangeProduct::price($record, $product)) === null ? [] : [
                            $product->id => __(':product: :price :cycle', ['product' => $product->name, 'price' => Money::format($price, $record->currency), 'cycle' => $record->billing_cycle->getLabel()]),
                        ])->all())
                    ->required(),
                Textarea::make('notes')
                    ->label('Anything we should know?')
                    ->rows(2)
                    ->maxLength(2000),
            ])
            ->modalSubmitActionLabel(__('Send request'))
            ->action(function (Service $record, array $data) {
                app(UpgradeRequests::class)->request($record, Product::findOrFail($data['product_id']), $data['notes'] ?? null);

                Notification::make()->title(__('Request sent'))->body(__('We will confirm by email.'))->success()->send();
            });
    }

    private static function withdraw(): Action
    {
        return Action::make('withdrawUpgrade')
            ->label('Withdraw package change request')
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('gray')
            ->visible(fn (Service $record) => self::pending($record) !== null)
            ->requiresConfirmation()
            ->modalDescription(__('The service continues as before.'))
            ->action(function (Service $record) {
                if ($request = self::pending($record)) {
                    app(UpgradeRequests::class)->withdraw($request);
                }

                Notification::make()->title(__('Request withdrawn'))->success()->send();
            });
    }
}
