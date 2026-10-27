<?php

namespace App\Filament\Forms;

use App\Enums\BillingCycle;
use App\Models\Product;
use App\Support\Money;
use App\Support\ProductOptions;
use Closure;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

/**
 * A field per option of the product (Settings → Product options), under options.{id}, each choice with what it adds
 * for the picked cycle. $changed runs after a pick changes, to update the price.
 */
class ProductOptionFields
{
    /**
     * @param  Closure(Get): ?BillingCycle  $cycle
     * @param  Closure(Get): ?string  $currency
     * @param  bool  $client  for the client's order form: without the options hidden from clients
     * @return list<Component>
     */
    public static function make(?Product $product, Closure $cycle, Closure $currency, Closure $changed, bool $client = false): array
    {
        return collect(ProductOptions::for($product, hiddenToo: ! $client))->map(function (array $option) use ($cycle, $currency, $changed) {
            $name = "options.{$option['id']}";
            $hint = ($option['hidden'] ?? false) ? __('Hidden from clients') : null;
            $added = fn (array $choice, Get $get) => ($c = $cycle($get))
                ? self::added(ProductOptions::price($choice, $c, $currency($get)), $currency($get))
                : '';

            $field = match ($option['type']) {
                'yesno' => Toggle::make($name)
                    ->label(fn (Get $get) => trim($option['name'].' '.(isset($option['choices'][0]) ? $added($option['choices'][0], $get) : ''))),
                'quantity' => TextInput::make($name)
                    ->label($option['name'])
                    ->integer()
                    ->minValue((int) ($option['min'] ?? 0))
                    ->maxValue(filled($option['max'] ?? null) ? (int) $option['max'] : null)
                    ->default((int) ($option['min'] ?? 0))
                    ->helperText(fn (Get $get) => isset($option['choices'][0]) && filled($each = $added($option['choices'][0], $get)) ? __('Each :price', ['price' => $each]) : null),
                'radio' => Radio::make($name)
                    ->label($option['name'])
                    ->required()
                    ->options(fn (Get $get) => collect($option['choices'] ?? [])->mapWithKeys(fn (array $choice) => [$choice['id'] => trim($choice['name'].' '.$added($choice, $get))])->all()),
                default => Select::make($name)
                    ->label($option['name'])
                    ->required()
                    ->native(false)
                    ->options(fn (Get $get) => collect($option['choices'] ?? [])->mapWithKeys(fn (array $choice) => [$choice['id'] => trim($choice['name'].' '.$added($choice, $get))])->all()),
            };

            return $field
                ->hint($hint)
                ->live(onBlur: $option['type'] === 'quantity')
                ->afterStateUpdated(fn (Get $get, Set $set, mixed $record = null) => $changed($get, $set, $record));
        })->all();
    }

    /** "+ €5.00 · setup €10.00", nothing when free. */
    private static function added(array $price, ?string $currency): string
    {
        return trim(($price['price'] > 0 ? '+ '.Money::format($price['price'], $currency) : '')
            .($price['setup'] > 0 ? ' · '.__('setup :amount', ['amount' => Money::format($price['setup'], $currency)]) : ''));
    }
}
