<?php

namespace App\Support;

use Illuminate\Support\Arr;
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

    public static function description(): ?string
    {
        $description = trim((string) config('gaiacp.seo.description'));

        return $description === '' ? null : $description;
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
