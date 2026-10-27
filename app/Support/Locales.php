<?php

namespace App\Support;

/**
 * The languages of the panel and of client emails: the ones App\Support\Translations finds, as switched on under
 * Settings → Languages (or fixed by a `gaiacp.locales` list). The first one is the default: what clients and admins
 * without a language of their own get. (Not `app.locale`, which follows whatever language is in use at the moment.)
 */
final class Locales
{
    /** @return array<string, string> code => name in that language, the default first */
    public static function available(): array
    {
        if (is_array($fixed = config('gaiacp.locales')) && $fixed !== []) {
            return $fixed;
        }

        $found = Translations::found();
        $enabled = config('gaiacp.languages.enabled');
        $languages = is_array($enabled) ? array_intersect_key($found, array_flip($enabled)) : $found;
        $default = (string) config('gaiacp.languages.default');
        $default = isset($found[$default]) ? $default : 'en';

        return [$default => $found[$default]] + $languages;
    }

    public static function default(): string
    {
        return (string) array_key_first(self::available());
    }

    /** Whether there is more than one language to choose from; the language fields show only then. */
    public static function multiple(): bool
    {
        return count(self::available()) > 1;
    }

    /** A known language code, or the default for null or anything not in the list. */
    public static function normalize(?string $locale): string
    {
        return $locale !== null && array_key_exists($locale, self::available()) ? $locale : self::default();
    }

    public static function isDefault(?string $locale): bool
    {
        return self::normalize($locale) === self::default();
    }

    /**
     * Run the callback in another language (emails to a client), then switch back.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function using(?string $locale, callable $callback): mixed
    {
        $original = app()->getLocale();
        $locale = self::normalize($locale);

        if ($locale === $original) {
            return $callback();
        }

        app()->setLocale($locale);

        try {
            return $callback();
        } finally {
            app()->setLocale($original);
        }
    }
}
