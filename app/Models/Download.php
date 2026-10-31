<?php

namespace App\Models;

use App\Enums\DownloadVisibility;
use App\Enums\ServiceStatus;
use App\Support\Locales;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;

/**
 * A file in Support → Downloads, kept on the private disk and handed out only through DownloadController, to whom
 * its visibility allows (visibleTo()). Deleting it deletes the file.
 *
 * @property array<string, array{title?: ?string, description?: ?string}> $texts
 * @property list<int>|null $product_ids
 */
#[Fillable(['download_category_id', 'texts', 'path', 'file_name', 'size', 'visibility', 'product_ids', 'published', 'sort'])]
class Download extends Model
{
    /** The private disk's folder of the files. */
    public const FOLDER = 'downloads';

    protected function casts(): array
    {
        return [
            'texts' => 'array',
            'product_ids' => 'array',
            'visibility' => DownloadVisibility::class,
            'published' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // Product ids as numbers, so whereJsonContains() finds them; none unless the visibility asks for products
        static::saving(fn (self $download) => $download->product_ids = $download->visibility === DownloadVisibility::Products
            ? array_values(array_map('intval', (array) $download->product_ids)) : null);
        static::deleted(fn (self $download) => Storage::disk('local')->delete($download->path));
        // A file replaced on edit: the old one goes
        static::updated(function (self $download) {
            if ($download->wasChanged('path') && filled($old = $download->getOriginal('path'))) {
                Storage::disk('local')->delete($old);
            }
        });
    }

    /** @return BelongsTo<DownloadCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(DownloadCategory::class, 'download_category_id');
    }

    public function title(?string $locale = null): string
    {
        return (string) $this->text('title', $locale);
    }

    public function description(?string $locale = null): ?string
    {
        return $this->text('description', $locale);
    }

    private function text(string $key, ?string $locale): ?string
    {
        $locale = Locales::normalize($locale ?? app()->getLocale());

        foreach ([$locale, Locales::default()] as $code) {
            if (filled($text = $this->texts[$code][$key] ?? null)) {
                return $text;
            }
        }

        // The description falls back with the title: a title in a language of its own has its own description
        return $key === 'title' ? collect($this->texts)->pluck('title')->filter()->first() : null;
    }

    public function humanSize(): string
    {
        return Number::fileSize($this->size, precision: 1);
    }

    /** @param  Builder<self>  $query */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort')->orderBy('id');
    }

    /**
     * The published downloads someone may have: visitors only those for everyone, a client also those for clients and
     * those of a product they have an active (or completed one-time) service of.
     *
     * @param  Builder<self>  $query
     */
    public function scopeVisibleTo(Builder $query, ?Client $client): void
    {
        $query->where('published', true)->where(function (Builder $query) use ($client) {
            $query->where('visibility', DownloadVisibility::Everyone->value);

            if ($client === null) {
                return;
            }

            $query->orWhere('visibility', DownloadVisibility::Clients->value);
            $products = $client->services()->whereIn('status', [ServiceStatus::Active, ServiceStatus::Completed])
                ->whereNotNull('product_id')->distinct()->pluck('product_id');

            foreach ($products as $product) {
                $query->orWhere(fn (Builder $query) => $query->where('visibility', DownloadVisibility::Products->value)
                    ->whereJsonContains('product_ids', (int) $product));
            }
        });
    }

    public function isVisibleTo(?Client $client): bool
    {
        return self::query()->visibleTo($client)->whereKey($this->getKey())->exists();
    }
}
