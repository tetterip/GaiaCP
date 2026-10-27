<?php

namespace App\Filament\Actions;

use App\Actions\ChangeProduct;
use App\Credit\ClientCredit;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Service;
use App\Providers\AppServiceProvider;
use App\Provisioning\ProvisioningException;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use InvalidArgumentException;

/** "Upgrade / downgrade" on a service's page (App\Actions\ChangeProduct). */
class ChangeProductAction
{
    public static function make(): Action
    {
        return Action::make('changeProduct')
            ->label('Upgrade / downgrade')
            ->icon(Heroicon::OutlinedArrowsUpDown)
            ->color('gray')
            ->visible(fn (Service $record) => ChangeProduct::changeable($record))
            ->modalDescription(fn (Service $record) => __('Now :product at :price :cycle.', [
                'product' => $record->product?->name ?? $record->label,
                'price' => Money::format($record->price_cents, $record->currency),
                'cycle' => $record->billing_cycle->getLabel(),
            ]).' '.__('The new package is set on the server now. The new price is owed from the next due date (:date); a payment notice already issued for it is updated.', [
                'date' => $record->next_due_date->format(AppServiceProvider::dateFormat()),
            ]))
            ->schema(fn (Service $record) => [
                Select::make('product_id')
                    ->label('New product')
                    ->options(fn () => ChangeProduct::products($record)->orderBy('name')->pluck('name', 'id'))
                    ->required()
                    ->live()
                    ->afterStateUpdated(function (Set $set, ?string $state) use ($record) {
                        $price = ($product = $state ? Product::find($state) : null) ? ChangeProduct::price($record, $product) : null;
                        $set('price', $price === null ? null : Money::toDecimal($price));
                    })
                    ->helperText(fn (Get $get) => ($product = $get('product_id') ? Product::find($get('product_id')) : null) && filled($product->package) && $product->package !== $record->package
                        ? __('Package :from → :to', ['from' => $record->package ?? '-', 'to' => $product->package])
                        : null),
                TextInput::make('price')
                    ->label(fn () => __('Price per cycle (:cycle)', ['cycle' => $record->billing_cycle->getLabel()]))
                    ->prefix($record->currency)
                    ->required()
                    ->live(onBlur: true)
                    ->rules(['regex:/^\d+([.,]\d{1,2})?$/'])
                    ->helperText(fn (Get $get) => ($product = $get('product_id') ? Product::find($get('product_id')) : null) && ChangeProduct::price($record, $product) === null
                        ? __('This product has no price for this cycle: enter one.')
                        : __('With the options it keeps.')),
                Toggle::make('charge_now')
                    ->label('Charge the difference now')
                    ->live()
                    ->helperText(fn (Get $get) => self::differenceText($record, $get('price'))),
            ])
            ->action(function (Service $record, array $data, Action $action) {
                try {
                    $result = app(ChangeProduct::class)->handle($record, Product::findOrFail($data['product_id']), Money::toCents($data['price']), (bool) $data['charge_now']);
                } catch (ProvisioningException|InvalidArgumentException $e) {
                    Notification::make()->title(__('Not changed'))->body($e->getMessage())->danger()->persistent()->send();
                    $action->halt();

                    return;
                }

                Notification::make()
                    ->title(__('Moved to :product', ['product' => $record->fresh()->product->name]))
                    ->body(self::resultText($result, $record->currency))
                    ->success()
                    ->send();
            });
    }

    /** The notice issued or the credit given for the difference, if any. @param  array{invoice: ?Invoice, credited: int}  $result */
    public static function resultText(array $result, string $currency): ?string
    {
        return match (true) {
            $result['invoice'] !== null => __('Payment notice :number for the difference, :amount.', ['number' => $result['invoice']->number, 'amount' => Money::format($result['invoice']->total_cents, $result['invoice']->currency)]),
            $result['credited'] > 0 => __(':amount added to the client\'s credit.', ['amount' => Money::format($result['credited'], $currency)]),
            default => null,
        };
    }

    /** What "charge the difference now" does with the price entered. */
    public static function differenceText(Service $record, ?string $price): string
    {
        if (blank($price) || ! preg_match('/^\d+([.,]\d{1,2})?$/', $price)) {
            return __('The days left until the next due date at the new price, less the current one.');
        }

        $difference = ChangeProduct::difference($record, Money::toCents($price));
        $amount = Money::format(abs($difference), $record->currency);

        return match (true) {
            $difference > 0 => __('A payment notice for :amount (before tax), due today, for the days left until :date.', ['amount' => $amount, 'date' => $record->next_due_date->format(AppServiceProvider::dateFormat())]),
            $difference < 0 && ClientCredit::enabled() => __(':amount goes into the client\'s credit for the days left until :date.', ['amount' => $amount, 'date' => $record->next_due_date->format(AppServiceProvider::dateFormat())]),
            $difference < 0 => __('The client is owed :amount for the days left; client credit is off, so nothing is refunded.', ['amount' => $amount]),
            default => __('No difference to charge.'),
        };
    }
}
