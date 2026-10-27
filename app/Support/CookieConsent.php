<?php

namespace App\Support;

/**
 * The cookie banner of the client area, /cart and sign up (Settings → Cookies). GaiaCP itself sets only the cookies
 * it needs to work (session, CSRF, remember me), so the banner says so; the scripts of Settings → Cookies (e.g. an
 * analytics snippet) are added to the page only after the visitor presses "Accept all". The choice is kept for
 * DAYS in the browser (cookie COOKIE: "all" or "necessary", set and read by the banner's own script, so the server
 * never needs it), and "Cookie settings" at the foot of the page asks again.
 */
class CookieConsent
{
    public const COOKIE = 'gaiacp_consent';

    public const DAYS = 180;

    public static function enabled(): bool
    {
        return (bool) config('gaiacp.cookies.banner');
    }

    /** The scripts that wait for consent (Google Analytics' tag first, when set), null when there are none (or in the demo). */
    public static function scripts(): ?string
    {
        if (Demo::enabled()) {
            return null; // anyone may log in there: their scripts would run for every visitor
        }

        $scripts = trim(self::googleAnalytics()."\n".config('gaiacp.cookies.scripts'));

        return $scripts === '' ? null : $scripts;
    }

    /** A GA4 measurement ID (G-…), as Settings → Cookies checks it. */
    public const GA_PATTERN = '/^G-[A-Z0-9]{4,20}$/i';

    /** Google's tag for the measurement ID of Settings → Cookies, or '' without one. */
    public static function googleAnalytics(): string
    {
        $id = strtoupper(trim((string) config('gaiacp.cookies.google_analytics')));

        if (! preg_match(self::GA_PATTERN, $id)) {
            return '';
        }

        return '<script async src="https://www.googletagmanager.com/gtag/js?id='.$id.'"></script>'
            ."<script>window.dataLayer = window.dataLayer || []; function gtag(){dataLayer.push(arguments);} gtag('js', new Date()); gtag('config', '{$id}');</script>";
    }

    public static function text(): string
    {
        $text = trim((string) config('gaiacp.cookies.text'));

        if ($text !== '') {
            return $text;
        }

        return self::scripts() === null
            ? __('We use only the cookies this site needs to work, such as keeping you signed in.')
            : __('We use the cookies this site needs to work, and, if you accept, cookies that help us understand how the site is used.');
    }
}
