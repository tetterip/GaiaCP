<?php

namespace App\Support;

use App\Enums\BillingCycle;
use App\Models\Product;
use App\Models\ProductOptionGroup;

/**
 * Product options (Settings → Product options): what a product offers, the price of a pick, and what a service keeps.
 *
 * Picks ("values") are {option id: choice id for a list or buttons, bool for yes/no, int for a quantity}. A service
 * keeps them as a list of {id, name, type, hidden, choice, value, quantity, price_cents, setup_cents} (select()),
 * priced in its currency for its cycle; a yes/no left off or a quantity of 0 is not kept.
 */
class ProductOptions
{
    public const TYPES = ['select', 'radio', 'yesno', 'quantity'];

    /**
     * The options of the product's groups, in order; for a client, without those hidden from clients.
     *
     * @return list<array<string, mixed>>
     */
    public static function for(?Product $product, bool $hiddenToo = true): array
    {
        if (! $product) {
            return [];
        }

        return $product->optionGroups()->orderBy('product_option_groups.name')->get()
            ->flatMap(fn (ProductOptionGroup $group) => array_values(array_filter(
                $group->options ?? [],
                fn ($option) => filled($option['id'] ?? null) && filled($option['name'] ?? null) && in_array($option['type'] ?? null, self::TYPES, true)
                    && ($hiddenToo || ! ($option['hidden'] ?? false)),
            )))
            ->values()->all();
    }

    /**
     * The first pick of each option: a list's first choice, yes/no off, a quantity's minimum.
     *
     * @param  list<array<string, mixed>>  $options
     * @return array<string, mixed>
     */
    public static function defaults(array $options): array
    {
        return collect($options)->mapWithKeys(fn (array $option) => [$option['id'] => match ($option['type']) {
            'yesno' => false,
            'quantity' => (int) ($option['min'] ?? 0),
            default => $option['choices'][0]['id'] ?? null,
        }])->all();
    }

    /**
     * The picks kept on a service, back as form values.
     *
     * @param  list<array<string, mixed>>|null  $kept
     * @return array<string, mixed>
     */
    public static function values(?array $kept): array
    {
        return collect($kept ?? [])->mapWithKeys(fn (array $pick) => [$pick['id'] => match ($pick['type'] ?? null) {
            'yesno' => true,
            'quantity' => (int) ($pick['quantity'] ?? 0),
            default => $pick['choice'] ?? null,
        }])->all();
    }

    /**
     * What a service keeps for these picks. A pick unchanged from $previous keeps its price then. An option with no
     * pick takes its first one, except one hidden from clients: only the admin picks those.
     *
     * @param  array<string, mixed>  $values
     * @param  list<array<string, mixed>>|null  $previous
     * @return list<array<string, mixed>>
     */
    public static function select(?Product $product, array $values, BillingCycle $cycle, ?string $currency = null, ?array $previous = null): array
    {
        $previous = collect($previous ?? [])->keyBy('id');
        $options = self::for($product);
        $values += self::defaults(array_filter($options, fn (array $option) => ! ($option['hidden'] ?? false)));
        $picks = [];

        foreach ($options as $option) {
            $value = $values[$option['id']] ?? null;
            $quantity = match ($option['type']) {
                'yesno' => filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 1 : 0,
                'quantity' => max((int) ($option['min'] ?? 0), min((int) $value, (int) ($option['max'] ?? PHP_INT_MAX))),
                default => 1,
            };
            $choice = in_array($option['type'], ['select', 'radio'], true)
                ? collect($option['choices'] ?? [])->firstWhere('id', $value)
                : ($option['choices'][0] ?? null);

            if (! $choice || $quantity < 1) {
                continue;
            }

            $kept = $previous->get($option['id']);

            if ($kept && ($kept['choice'] ?? null) === $choice['id'] && (int) ($kept['quantity'] ?? 0) === $quantity) {
                $picks[] = $kept;

                continue;
            }

            $price = self::price($choice, $cycle, $currency);

            $picks[] = [
                'id' => $option['id'],
                'name' => $option['name'],
                'type' => $option['type'],
                'hidden' => (bool) ($option['hidden'] ?? false),
                'choice' => $choice['id'],
                'value' => match ($option['type']) {
                    'yesno' => __('Yes'),
                    'quantity' => (string) $quantity,
                    default => (string) ($choice['name'] ?? ''),
                },
                'quantity' => $quantity,
                'price_cents' => $price['price'] * $quantity,
                'setup_cents' => $price['setup'] * $quantity,
            ];
        }

        return $picks;
    }

    /**
     * A choice's price per $cycle and its setup fee, in $currency: the cycle's own price, else the monthly one times
     * the cycle's months.
     *
     * @param  array<string, mixed>  $choice
     * @return array{price: int, setup: int}
     */
    public static function price(array $choice, BillingCycle $cycle, ?string $currency = null): array
    {
        $prices = $choice['prices'] ?? [];
        $own = $prices[$cycle->value] ?? null;
        $price = $own !== null && $own !== '' ? (int) $own : (int) ($prices[BillingCycle::Monthly->value] ?? 0) * $cycle->months();

        return [
            'price' => Currencies::fromBase($price, $currency),
            'setup' => Currencies::fromBase((int) ($choice['setup_cents'] ?? 0), $currency),
        ];
    }

    /** @param  list<array<string, mixed>>|null  $picks  what they add to the price per cycle */
    public static function total(?array $picks): int
    {
        return (int) collect($picks ?? [])->sum('price_cents');
    }

    /** @param  list<array<string, mixed>>|null  $picks  what they add to the first payment */
    public static function setupTotal(?array $picks): int
    {
        return (int) collect($picks ?? [])->sum('setup_cents');
    }

    /**
     * "Location: Athens, Extra IPs: 2", for a payment notice line; hidden options only for the admin.
     *
     * @param  list<array<string, mixed>>|null  $picks
     */
    public static function describe(?array $picks, bool $hiddenToo = false): string
    {
        return collect($picks ?? [])
            ->reject(fn (array $pick) => ! $hiddenToo && ($pick['hidden'] ?? false))
            ->map(fn (array $pick) => ($pick['type'] ?? null) === 'yesno' ? $pick['name'] : $pick['name'].': '.$pick['value'])
            ->implode(', ');
    }
}
