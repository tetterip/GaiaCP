<?php

namespace App\Models;

use App\Support\Locales;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Normalizer;

/**
 * A help article in the client area (Support → Knowledge base): a title and Markdown text per language, the default
 * language's required and the others falling back to it. Clients see the published ones (App\Filament\Client\Pages\
 * KnowledgeBase), search them, and are offered matching ones while they write a ticket. Visitors see them too while
 * Settings → Public area says so, except the ones kept for clients (`clients_only`).
 *
 * @property array<string, array{title: ?string, body: ?string}> $texts
 */
#[Fillable(['kb_category_id', 'slug', 'texts', 'published', 'clients_only', 'sort', 'created_by'])]
class KbArticle extends Model
{
    protected $attributes = [
        'published' => false,
        'clients_only' => false,
    ];

    protected function casts(): array
    {
        return [
            'texts' => 'array',
            'published' => 'boolean',
            'clients_only' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $article) {
            $article->slug = filled($article->slug) ? Str::slug($article->slug) : null;
            $article->slug = $article->slug ?: self::uniqueSlug($article->text(Locales::default())['title'], $article->id);
            $article->search_text = self::searchable(collect($article->texts ?? [])
                ->map(fn (array $text) => ($text['title'] ?? '').' '.($text['body'] ?? ''))->implode(' '));
        });
    }

    /** @return BelongsTo<KbCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(KbCategory::class, 'kb_category_id');
    }

    /**
     * The title and text in a language, else in the default one.
     *
     * @return array{title: string, body: string}
     */
    public function text(?string $locale = null): array
    {
        $texts = $this->texts ?? [];
        $own = $texts[Locales::normalize($locale ?? app()->getLocale())] ?? null;
        $text = filled($own['title'] ?? null) && filled($own['body'] ?? null) ? $own : ($texts[Locales::default()] ?? []);

        return ['title' => (string) ($text['title'] ?? ''), 'body' => (string) ($text['body'] ?? '')];
    }

    public function title(?string $locale = null): string
    {
        return $this->text($locale)['title'];
    }

    /** @param  Builder<self>  $query */
    public function scopePublished(Builder $query): void
    {
        $query->where('published', true);
    }

    /**
     * Published, and for a visitor (nobody logged in to the client area) not kept for clients.
     *
     * @param  Builder<self>  $query
     */
    public function scopeShown(Builder $query): void
    {
        $query->where('published', true)->when(! filament()->auth()->check(), fn (Builder $query) => $query->where('clients_only', false));
    }

    /** @param  Builder<self>  $query */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort')->orderBy('id');
    }

    /**
     * Articles with every word of the query in a title or text, in any language; accents and case don't matter.
     *
     * @param  Builder<self>  $query
     */
    public function scopeMatching(Builder $query, string $search): void
    {
        $words = collect(preg_split('/\s+/u', self::searchable($search)))->filter(fn (string $word) => mb_strlen($word) >= 2)->take(8);

        $words->isEmpty()
            ? $query->whereRaw('1 = 0')
            : $words->each(fn (string $word) => $query->where('search_text', 'like', '%'.addcslashes($word, '%_\\').'%'));
    }

    /** Lower case, without accents and punctuation: "Ρύθμιση email;" → "ρυθμιση email". */
    public static function searchable(string $text): string
    {
        $text = mb_strtolower(strip_tags($text));
        $text = class_exists(Normalizer::class) ? (string) preg_replace('/\p{Mn}+/u', '', (string) Normalizer::normalize($text, Normalizer::FORM_D)) : $text;

        return trim((string) preg_replace(['/[^\p{L}\p{N}]+/u', '/\s+/u'], ' ', $text));
    }

    private static function uniqueSlug(string $title, ?int $id): string
    {
        $base = Str::limit(Str::slug($title), 100, '') ?: 'article';
        $slug = $base;

        for ($i = 2; self::query()->where('slug', $slug)->when($id, fn ($q) => $q->whereKeyNot($id))->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }
}
