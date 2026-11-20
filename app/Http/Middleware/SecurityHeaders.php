<?php

namespace App\Http\Middleware;

use App\Support\ContentSecurityPolicy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every page:
 * - a Content-Security-Policy (App\Support\ContentSecurityPolicy): scripts run only with the page's nonce, so a script
 *   slipped into a page doesn't; with it on "report", browsers only report what it would block;
 * - only GaiaCP's own pages may show it in a frame (its email previews do), so another site can't put the panel in a
 *   hidden frame and have an admin or client click its buttons (clickjacking);
 * - over https, browsers are told to keep using https for this address (HSTS, `gaiacp.security.hsts` seconds, 0 = off),
 *   without includeSubDomains, which would also bind the owner's other sites;
 * - other sites see only the address's origin as referrer, never its paths;
 * - the camera, microphone and location are off (nothing here uses them).
 * A header a page already set is kept.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        // Made before the page is drawn, so Filament, Livewire and @nonce put it on their scripts
        $nonce = ContentSecurityPolicy::nonce();

        $response = $next($request);
        $headers = $response->headers;

        $set = function (string $name, string $value) use ($headers): void {
            if (! $headers->has($name)) {
                $headers->set($name, $value);
            }
        };

        $set('X-Frame-Options', 'SAMEORIGIN');
        // A page with a policy of its own (an SVG logo, an email preview) keeps it
        if (! $headers->has('Content-Security-Policy') && $nonce !== null && ContentSecurityPolicy::mode() === ContentSecurityPolicy::REPORT) {
            $headers->set('Content-Security-Policy-Report-Only', ContentSecurityPolicy::header($nonce));
        }
        $set('Content-Security-Policy', $nonce !== null && ContentSecurityPolicy::mode() === ContentSecurityPolicy::ENFORCE
            ? ContentSecurityPolicy::header($nonce)
            : "frame-ancestors 'self'");
        $set('X-Content-Type-Options', 'nosniff');
        $set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        $hsts = (int) config('gaiacp.security.hsts', 0);
        if ($hsts > 0 && $request->isSecure()) {
            $set('Strict-Transport-Security', "max-age={$hsts}");
        }

        return $response;
    }
}
