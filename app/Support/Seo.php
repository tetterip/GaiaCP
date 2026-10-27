<?php

namespace App\Support;

/**
 * What search engines and link previews see of the client area (Settings → Client area → Search engines): the
 * company's name, a description and its logo for previews on social networks and chats, and whether the pages a
 * visitor sees before signing in (login, sign up, the public order page) may be indexed. Signed-in pages and /admin
 * are never indexed, nor is the public demo.
 */
class Seo
{
    public static function indexable(): bool
    {
        return (bool) config('gaiacp.seo.index') && ! filament()->auth()->check() && ! Demo::enabled();
    }

    public static function description(): ?string
    {
        $description = trim((string) config('gaiacp.seo.description'));

        return $description === '' ? null : $description;
    }
}
