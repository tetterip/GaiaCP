<?php

namespace App\Models;

use App\Support\Locales;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A group of knowledge base articles (Settings → Knowledge base categories), named in each language.
 *
 * @property array<string, ?string> $names
 */
#[Fillable(['names', 'sort'])]
class KbCategory extends Model
{
    protected function casts(): array
    {
        return ['names' => 'array'];
    }

    /** @return HasMany<KbArticle, $this> */
    public function articles(): HasMany
    {
        return $this->hasMany(KbArticle::class);
    }

    /** Its name in a language, else in the default one. */
    public function name(?string $locale = null): string
    {
        $names = $this->names ?? [];

        return (string) (filled($names[Locales::normalize($locale ?? app()->getLocale())] ?? null)
            ? $names[Locales::normalize($locale ?? app()->getLocale())]
            : ($names[Locales::default()] ?? collect($names)->filter()->first() ?? ''));
    }

    /** @param  Builder<self>  $query */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort')->orderBy('id');
    }
}
