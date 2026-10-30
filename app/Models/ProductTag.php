<?php

namespace App\Models;

use App\Support\Locales;
use Filament\Support\Colors\Color;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A free label on products (Settings → Product tags): New, SSD, Old plan. Named in each language with a colour from
 * COLORS. A visible one is a badge beside Popular on the product's order card, in the compare table and on the public
 * home page (Product::badges(), at most Product::BADGES); every one filters Products, Services and Messages to clients.
 *
 * @property array<string, ?string> $names
 */
#[Fillable(['names', 'color', 'visible', 'sort'])]
class ProductTag extends Model
{
    /** The colours a tag can have: key => the badge's colour on the client area (light; dark mixes it with white). */
    public const COLORS = [
        'gray' => '#4b5563',
        'red' => '#dc2626',
        'orange' => '#ea580c',
        'amber' => '#b45309',
        'green' => '#16a34a',
        'teal' => '#0d9488',
        'blue' => '#2563eb',
        'indigo' => '#4f46e5',
        'purple' => '#9333ea',
        'pink' => '#db2777',
    ];

    protected $attributes = [
        'color' => 'gray',
        'visible' => true,
    ];

    protected function casts(): array
    {
        return [
            'names' => 'array',
            'visible' => 'boolean',
        ];
    }

    /** @return BelongsToMany<Product, $this> */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class);
    }

    /** Its name in a language, else in the default one. */
    public function name(?string $locale = null): string
    {
        $names = $this->names ?? [];
        $locale = Locales::normalize($locale ?? app()->getLocale());

        foreach ([$names[$locale] ?? null, $names[Locales::default()] ?? null, ...array_values($names)] as $name) {
            if (filled($name)) {
                return (string) $name;
            }
        }

        return '';
    }

    /** Its colour on the client area. */
    public function hex(): string
    {
        return self::COLORS[$this->color] ?? self::COLORS['gray'];
    }

    /** @return array<string, string> its colour in the admin panel (a Filament palette) */
    public function filamentColor(): array
    {
        return self::palette($this->color);
    }

    /** @return array<string, string> the colour picker's options: key => its name */
    public static function colorOptions(): array
    {
        return [
            'gray' => __('Gray'),
            'red' => __('Red'),
            'orange' => __('Orange'),
            'amber' => __('Amber'),
            'green' => __('Green'),
            'teal' => __('Teal'),
            'blue' => __('Blue'),
            'indigo' => __('Indigo'),
            'purple' => __('Purple'),
            'pink' => __('Pink'),
        ];
    }

    /** @return array<string, string> */
    public static function palette(?string $color): array
    {
        $name = Color::class.'::'.ucfirst(array_key_exists((string) $color, self::COLORS) ? (string) $color : 'gray');

        return constant($name);
    }

    /** @return array<int, string> every tag by id with its name, in order, for pickers and filters */
    public static function options(): array
    {
        return self::query()->ordered()->get()->mapWithKeys(fn (self $tag) => [$tag->id => $tag->name()])->all();
    }

    /** @param  Builder<self>  $query */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort')->orderBy('id');
    }
}
