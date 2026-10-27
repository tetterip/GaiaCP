<?php

namespace App\Models;

use App\Support\Locales;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A group of products on the order page, the public home page and the public side menu (Settings → Product groups):
 * Shared hosting, VPS, SSL certificates. Named and described in each language, with an icon, a position and its own
 * link (/order/<slug>, /cart/<slug>). A hidden group is left out of the lists, while its link still opens it.
 * Its `features` are the rows of the compare table under its products ({key, names: {locale: name}}); each product
 * keeps its value for each key (Product::$features). Products without a group show under "Other".
 *
 * @property array<string, ?string> $names
 * @property array<string, ?string>|null $descriptions
 * @property list<array{key: string, names: array<string, ?string>}>|null $features
 */
#[Fillable(['slug', 'names', 'descriptions', 'icon', 'features', 'sort', 'hidden'])]
class ProductGroup extends Model
{
    protected function casts(): array
    {
        return [
            'names' => 'array',
            'descriptions' => 'array',
            'features' => 'array',
            'hidden' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $group) {
            $group->slug = Str::slug((string) $group->slug) ?: Str::slug($group->name(Locales::default())) ?: 'group';

            // A feature keeps its key, so the products' values stay with it when it is renamed or moved
            $group->features = collect($group->features ?? [])
                ->filter(fn ($feature) => is_array($feature) && collect($feature['names'] ?? [])->filter(fn ($name) => filled($name))->isNotEmpty())
                ->map(fn (array $feature) => ['key' => filled($feature['key'] ?? null) ? (string) $feature['key'] : Str::lower(Str::random(8)), 'names' => array_map(fn ($name) => filled($name) ? trim((string) $name) : null, (array) $feature['names'])])
                ->values()->all();
        });
    }

    /** @return HasMany<Product, $this> */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /** Its name in a language, else in the default one. */
    public function name(?string $locale = null): string
    {
        return self::translated($this->names ?? [], $locale) ?? '';
    }

    public function description(?string $locale = null): ?string
    {
        return self::translated($this->descriptions ?? [], $locale);
    }

    /** @return array<string, string> the compare table's rows: feature key => its name in the current language */
    public function featureNames(): array
    {
        return collect($this->features ?? [])->mapWithKeys(fn (array $feature) => [$feature['key'] => self::translated($feature['names'] ?? [], null) ?? ''])->all();
    }

    /** @param  Builder<self>  $query */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort')->orderBy('id');
    }

    /** @param  array<string, ?string>  $texts */
    private static function translated(array $texts, ?string $locale): ?string
    {
        $locale = Locales::normalize($locale ?? app()->getLocale());

        foreach ([$texts[$locale] ?? null, $texts[Locales::default()] ?? null, ...array_values($texts)] as $text) {
            if (filled($text)) {
                return (string) $text;
            }
        }

        return null;
    }
}
