<?php

namespace App\Support;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Livewire\Component;

/**
 * What search engines and link previews see of the client area (Settings → Client area → Search engines): the
 * company's name, a description and its logo for previews on social networks and chats, and whether the pages a
 * visitor sees before signing in (login, sign up, the public area, the public order page) may be indexed. Signed-in
 * pages and /admin are never indexed, nor is the public demo.
 *
 * A public page's language is in its address (`?lang=el`, SetLocale; none for the default language), so each language
 * is a page of its own: every one names its canonical address and the others (hreflang), and /sitemap.xml lists them.
 */
class Seo
{
    /** The query parameter with a page's language (SetLocale keeps it for the visitor's session). */
    public const LANGUAGE = 'lang';

    /** The query parameters that change what a page shows, kept in its canonical address. */
    private const KEPT = ['page'];

    /** The Filament page being shown (AppServiceProvider, from the panel's layout). */
    private static ?Component $page = null;

    public static function showing(mixed $page): void
    {
        self::$page = $page instanceof Component ? $page : null;
    }

    /** Whether search engines may index the site at all (Settings → Client area → Search engines; never the demo). */
    public static function open(): bool
    {
        return (bool) config('gaiacp.seo.index') && ! Demo::enabled();
    }

    public static function indexable(): bool
    {
        return self::open() && ! filament()->auth()->check();
    }

    /** The open page's own picture for link previews (a page of your own's featured image), else null for the logo. */
    public static function image(): ?string
    {
        $page = self::$page;

        return $page && method_exists($page, 'metaImage') ? $page->metaImage() : null;
    }

    /** Whether the open page asks to stay out of search results (a product page without a text of its own). */
    public static function kept(): bool
    {
        $page = self::$page;

        return $page && method_exists($page, 'noindex') && $page->noindex();
    }

    /** The open page's own description (a product group's page), else the site's. */
    public static function description(): ?string
    {
        $page = self::$page;
        $description = $page && method_exists($page, 'metaDescription') ? self::plain($page->metaDescription()) : null;

        return $description ?? self::plain(config('gaiacp.seo.description'));
    }

    /**
     * The open page's schema.org data (a product group's page), for search engines.
     *
     * @return list<array<string, mixed>>
     */
    public static function structuredData(): array
    {
        $page = self::$page;

        return self::indexable() && ! self::kept() && $page && method_exists($page, 'structuredData') ? $page->structuredData() : [];
    }

    /** A text as one plain line (tags and line breaks gone), at most 300 characters; null when empty. */
    public static function plain(mixed $text): ?string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string) $text), ENT_QUOTES | ENT_HTML5)));

        return $text === '' ? null : Str::limit($text, 300);
    }

    /** The open page's title with the company's name (as in the browser tab), for link previews. */
    public static function title(): string
    {
        $name = config('gaiacp.company.name') ?: 'GaiaCP';
        $page = self::$page;
        $title = $page && method_exists($page, 'getTitle') ? trim(strip_tags((string) $page->getTitle())) : '';

        return $title === '' || $title === $name ? $name : "{$title} - {$name}";
    }

    /** The address of a page in a language: its path and the parameters it keeps, with `?lang=` unless the default. */
    public static function localized(string $url, ?string $locale, array $query = []): string
    {
        $query = Arr::only($query, self::KEPT);

        if (! Locales::isDefault($locale)) {
            $query[self::LANGUAGE] = Locales::normalize($locale);
        }

        return $query === [] ? $url : $url.'?'.http_build_query($query);
    }

    /** The open page's address in its language. */
    public static function canonical(): string
    {
        return self::localized(url()->current(), app()->getLocale(), request()->query());
    }

    /**
     * The open page in each language, while there is more than one, plus the default as x-default.
     *
     * @return array<string, string> hreflang => address
     */
    public static function alternates(?string $url = null, ?array $query = null): array
    {
        if (! Locales::multiple()) {
            return [];
        }

        $url ??= url()->current();
        $query ??= request()->query();
        $alternates = [];

        foreach (array_keys(Locales::available()) as $locale) {
            $alternates[self::hreflang($locale)] = self::localized($url, $locale, $query);
        }

        return $alternates + ['x-default' => self::localized($url, Locales::default(), $query)];
    }

    /** pt_BR → pt-BR */
    public static function hreflang(string $locale): string
    {
        return str_replace('_', '-', $locale);
    }

    /** An address without its `?lang=`, so going back to it after picking a language keeps the new one. */
    public static function withoutLanguage(string $url): string
    {
        $parts = explode('?', $url, 2);

        if (count($parts) < 2) {
            return $url;
        }

        parse_str($parts[1], $query);
        unset($query[self::LANGUAGE]);

        return $query === [] ? $parts[0] : $parts[0].'?'.http_build_query($query);
    }
}
