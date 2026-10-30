<?php

namespace App\Models;

use App\Support\Locales;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * A group of products on the order page, the public home page and the public side menu (Settings → Product groups):
 * Shared hosting, VPS, SSL certificates. Named and described in each language, with an icon, a position and its own
 * link (/order/<slug>, /cart/<slug>). A hidden group is left out of the lists, while its link still opens it.
 * Its `features` are the rows of the compare table under its products ({key, names: {locale: name}}); each product
 * keeps its value for each key (Product::$features). Products without a group show under "Other".
 * With `page` on it also has a public page at /<slug> (ProductGroupPage): its title and search result description,
 * a text (Markdown) and questions with their answers (`faq`: {questions: {locale: text}, answers: {locale: text}}).
 *
 * @property array<string, ?string> $names
 * @property array<string, ?string>|null $descriptions
 * @property list<array{key: string, names: array<string, ?string>}>|null $features
 * @property array<string, ?string>|null $page_titles
 * @property array<string, ?string>|null $page_descriptions
 * @property array<string, ?string>|null $page_texts
 * @property list<array{questions: array<string, ?string>, answers: array<string, ?string>}>|null $faq
 */
#[Fillable(['slug', 'names', 'descriptions', 'icon', 'features', 'sort', 'hidden', 'page', 'page_titles', 'page_descriptions', 'page_texts', 'faq'])]
class ProductGroup extends Model
{
    protected function casts(): array
    {
        return [
            'names' => 'array',
            'descriptions' => 'array',
            'features' => 'array',
            'hidden' => 'boolean',
            'page' => 'boolean',
            'page_titles' => 'array',
            'page_descriptions' => 'array',
            'page_texts' => 'array',
            'faq' => 'array',
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

            $group->faq = collect($group->faq ?? [])
                ->filter(fn ($item) => is_array($item) && collect($item['questions'] ?? [])->filter(fn ($text) => filled($text))->isNotEmpty())
                ->map(fn (array $item) => [
                    'questions' => array_map(fn ($text) => filled($text) ? trim((string) $text) : null, (array) $item['questions']),
                    'answers' => array_map(fn ($text) => filled($text) ? trim((string) $text) : null, (array) ($item['answers'] ?? [])),
                ])
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

    /**
     * The first parts of the client area's own addresses (login, cart, status…), which a group's link can't be, as its
     * public page would sit behind them.
     *
     * @return list<string>
     */
    public static function reservedSlugs(): array
    {
        return collect(Route::getRoutes()->getRoutes())
            ->map(fn ($route) => explode('/', trim($route->uri(), '/'))[0])
            ->filter(fn (string $segment) => $segment !== '' && ! str_starts_with($segment, '{'))
            ->merge(['admin', 'install', 'livewire', 'storage', 'build', 'css', 'js', 'fonts', 'images'])
            ->unique()->values()->all();
    }

    /** The title of its public page (and of its search result), else its name. */
    public function pageTitle(): string
    {
        return self::translated($this->page_titles ?? [], null) ?? $this->name();
    }

    /** What search results and link previews say about its page, else its description. */
    public function pageDescription(): ?string
    {
        return self::translated($this->page_descriptions ?? [], null) ?? $this->description();
    }

    /** The text of its public page (Markdown), shown in the current language else the default one. */
    public function pageText(): ?HtmlString
    {
        $text = self::translated($this->page_texts ?? [], null);

        return $text === null ? null : new HtmlString(Str::markdown($text, ['html_input' => 'escape', 'allow_unsafe_links' => false]));
    }

    /** @return list<array{question: string, answer: string}> its questions with an answer, in the current language */
    public function questions(): array
    {
        return collect($this->faq ?? [])
            ->map(fn (array $item) => ['question' => self::translated($item['questions'] ?? [], null), 'answer' => self::translated($item['answers'] ?? [], null)])
            ->filter(fn (array $item) => $item['question'] !== null && $item['answer'] !== null)
            ->values()->all();
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
