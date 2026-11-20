<?php

namespace App\Support;

use Illuminate\Support\Facades\Vite;

/**
 * The Content-Security-Policy of every page (App\Http\Middleware\SecurityHeaders), `gaiacp.security.csp`:
 * "enforce" (default), "report" (browsers only report what it would block, e.g. while trying a plugin) or "off".
 *
 * Scripts run only from a `<script>` tag carrying the page's nonce (Vite::cspNonce(), which Filament and Livewire add
 * themselves; `@nonce` in a Blade view), or one such a script adds ('strict-dynamic': captchas, the cookie banner's
 * scripts, Filament's lazy components). A `<script>` or onclick="" written into a page by anyone else does not run.
 * 'unsafe-eval' stays, as Alpine (Filament's JavaScript) evaluates its x-data / x-on expressions with new Function().
 * Styles, images, fonts, frames and connections may come from any https address (captchas, maps, analytics, a chat
 * widget, payment pages), forms post to this site or an https one (banks' payment pages), no plugins (<object>), and
 * only GaiaCP's own pages may frame it. 'self', https: and 'unsafe-inline' in script-src are only for browsers too old
 * for nonces and 'strict-dynamic', which ignore them otherwise.
 */
class ContentSecurityPolicy
{
    public const ENFORCE = 'enforce';

    public const REPORT = 'report';

    public const OFF = 'off';

    /**
     * Inline scripts of plugins written before the nonce, allowed by their hash: the catalogue's Alpha Bank, Eurobank and
     * Piraeus Bank plugins (AutoPostForm, up to 1.0.x), which submit the form to the bank (a button does it otherwise).
     */
    public const KNOWN_SCRIPTS = [
        'document.getElementById("bank").submit();',
    ];

    public static function mode(): string
    {
        $mode = strtolower(trim((string) config('gaiacp.security.csp', self::ENFORCE)));

        return in_array($mode, [self::ENFORCE, self::REPORT, self::OFF], true) ? $mode : self::ENFORCE;
    }

    /** The page's nonce, made on first use (Vite::useCspNonce()); null with the policy off. */
    public static function nonce(): ?string
    {
        if (self::mode() === self::OFF) {
            return null;
        }

        return Vite::cspNonce() ?? Vite::useCspNonce();
    }

    /** The policy for a page with this nonce. */
    public static function header(string $nonce): string
    {
        $hashes = collect(self::KNOWN_SCRIPTS)->map(fn (string $script) => "'sha256-".base64_encode(hash('sha256', $script, true))."'")->implode(' ');

        return implode('; ', [
            "default-src 'self'",
            "script-src 'nonce-{$nonce}' 'strict-dynamic' 'unsafe-eval' {$hashes} 'self' https: 'unsafe-inline'",
            "style-src 'self' 'unsafe-inline' https:",
            "img-src 'self' data: blob: https:",
            "font-src 'self' data: https:",
            "connect-src 'self' https: wss:",
            "media-src 'self' data: blob: https:",
            "frame-src 'self' https:",
            "worker-src 'self' blob:",
            "manifest-src 'self'",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self' https:",
            "frame-ancestors 'self'",
            'report-uri '.route('csp-report', absolute: false),
        ]);
    }
}
