<?php

namespace App\Models;

use App\Support\Locales;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * News from the company (Support → Announcements): a title and Markdown text per language, the default language's
 * required and the others falling back to it. It shows from `published_at` (empty: a draft; a later date: scheduled)
 * on the client area's Announcements page, newest first; visitors see it too while Settings → Public area says so,
 * unless it is kept for clients (`clients_only`), and the public ones are in the RSS feed.
 *
 * @property array<string, array{title: ?string, body: ?string}> $texts
 * @property CarbonImmutable|null $published_at
 */
#[Fillable(['slug', 'texts', 'published_at', 'clients_only', 'created_by'])]
class Announcement extends Model
{
    protected $attributes = [
        'clients_only' => false,
    ];

    protected function casts(): array
    {
        return [
            'texts' => 'array',
            'published_at' => 'immutable_datetime',
            'clients_only' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $announcement) {
            $slug = Str::limit(Str::slug((string) $announcement->slug), 100, '');
            $base = $slug ?: (Str::limit(Str::slug($announcement->title(Locales::default())), 100, '') ?: 'announcement');
            $slug = $base;

            for ($i = 2; self::query()->where('slug', $slug)->when($announcement->id, fn ($q) => $q->whereKeyNot($announcement->id))->exists(); $i++) {
                $slug = "{$base}-{$i}";
            }

            $announcement->slug = $slug;
        });
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
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

    /** Its text as HTML (Markdown, with any HTML in it escaped). */
    public function html(?string $locale = null): HtmlString
    {
        return new HtmlString(Str::markdown($this->text($locale)['body'], ['html_input' => 'escape', 'allow_unsafe_links' => false]));
    }

    /** Its first lines as plain text, for a list or the feed. */
    public function excerpt(int $length = 220): string
    {
        return Str::limit(trim((string) preg_replace('/\s+/u', ' ', strip_tags($this->html()->toHtml()))), $length);
    }

    /** @param  Builder<self>  $query published by now */
    public function scopePublished(Builder $query): void
    {
        $query->whereNotNull('published_at')->where('published_at', '<=', now());
    }

    /**
     * Published, and for a visitor (nobody logged in to the client area) not kept for clients; newest first.
     *
     * @param  Builder<self>  $query
     */
    public function scopeShown(Builder $query): void
    {
        $query->published()
            ->when(! filament()->auth()->check(), fn (Builder $query) => $query->where('clients_only', false))
            ->orderByDesc('published_at')->orderByDesc('id');
    }
}
