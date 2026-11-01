<?php

namespace App\Models;

use App\Support\Locales;
use App\Support\PageImages;
use Filament\Forms\Components\RichEditor\RichContentRenderer;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * A page of your own (Support → Pages): About us, Terms, a guide. A title, a text written with the editor (HTML) and a
 * description for search engines per language, the default language's title and text required and the others falling
 * back to it. A featured image (`image`, App\Support\PageImages) shows above the text and in link previews. Published, it is at /pages/{slug} (App\Filament\Client\Pages\CustomPageView) and can be added to the
 * client area's menus (Settings → Client area → Menus); visitors open it too, unless it is kept for clients.
 *
 * @property array<string, array{title: ?string, body: ?string, description: ?string}> $texts
 */
#[Fillable(['slug', 'image', 'texts', 'published', 'clients_only', 'created_by'])]
class CustomPage extends Model
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
        static::saving(function (self $page) {
            $slug = Str::limit(Str::slug((string) $page->slug), 100, '');
            $base = $slug ?: (Str::limit(Str::slug($page->title(Locales::default())), 100, '') ?: 'page');
            $slug = $base;

            for ($i = 2; self::query()->where('slug', $slug)->when($page->id, fn ($q) => $q->whereKeyNot($page->id))->exists(); $i++) {
                $slug = "{$base}-{$i}";
            }

            $page->slug = $slug;
        });
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The title, text and description in a language, else the default language's (the three go together).
     *
     * @return array{title: string, body: string, description: string}
     */
    public function text(?string $locale = null): array
    {
        $texts = $this->texts ?? [];
        $default = $texts[Locales::default()] ?? [];
        $own = $texts[Locales::normalize($locale ?? app()->getLocale())] ?? [];
        $text = filled($own['title'] ?? null) && self::filledBody($own['body'] ?? null) ? $own : $default;

        return [
            'title' => (string) ($text['title'] ?? ''),
            'body' => (string) ($text['body'] ?? ''),
            'description' => (string) ($text['description'] ?? ''),
        ];
    }

    public function title(?string $locale = null): string
    {
        return $this->text($locale)['title'];
    }

    /** Its text as safe HTML: the editor's HTML run through Filament's sanitizer, its images by their own address. */
    public function html(?string $locale = null): HtmlString
    {
        return new HtmlString(RichContentRenderer::make($this->text($locale)['body'])
            ->fileAttachmentProvider(new PageImages)
            ->toHtml());
    }

    /** Its description for search engines, else its first lines. */
    public function description(?string $locale = null): string
    {
        $text = $this->text($locale);

        return filled($text['description']) ? $text['description'] : Str::limit(trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($text['body']), ENT_QUOTES | ENT_HTML5))), 160);
    }

    /** Its featured image's address (App\Support\PageImages), or null. */
    public function imageUrl(): ?string
    {
        return PageImages::url($this->image);
    }

    /** @param  Builder<self>  $query */
    public function scopePublished(Builder $query): void
    {
        $query->where('published', true);
    }

    /** Whether an editor's text has anything in it: an empty editor leaves "<p></p>". */
    public static function filledBody(mixed $body): bool
    {
        return is_string($body) && (trim(strip_tags($body)) !== '' || str_contains($body, '<img'));
    }
}
