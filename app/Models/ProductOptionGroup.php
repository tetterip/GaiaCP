<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

/**
 * Settings → Product options: options offered with the products the group is attached to (App\Support\ProductOptions).
 *
 * options: [{id, name, type (select / radio / yesno / quantity), hidden, min, max, choices: [{id, name, prices:
 * {cycle: cents}, setup_cents}]}]. Prices are in the base currency; a cycle without a price of its own is the monthly
 * one times its months. A yes/no or quantity option has one choice: the price when on, or per unit.
 *
 * @property list<array<string, mixed>>|null $options
 */
#[Fillable(['name', 'description', 'options'])]
class ProductOptionGroup extends Model
{
    protected static function booted(): void
    {
        // Services keep picks by these ids, so every option and choice has one that never changes.
        static::saving(function (ProductOptionGroup $group) {
            $group->options = array_values(array_map(fn (array $option) => [
                ...$option,
                'id' => filled($option['id'] ?? null) ? $option['id'] : (string) Str::uuid(),
                'choices' => array_values(array_map(fn (array $choice) => [
                    ...$choice,
                    'id' => filled($choice['id'] ?? null) ? $choice['id'] : (string) Str::uuid(),
                ], $option['choices'] ?? [])),
            ], $group->options ?? []));
        });
    }

    protected function casts(): array
    {
        return [
            'options' => 'array',
        ];
    }

    /** @return BelongsToMany<Product, $this> */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class);
    }
}
