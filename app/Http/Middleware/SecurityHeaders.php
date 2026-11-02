<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every page:
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
        $response = $next($request);
        $headers = $response->headers;

        $set = function (string $name, string $value) use ($headers): void {
            if (! $headers->has($name)) {
                $headers->set($name, $value);
            }
        };

        $set('X-Frame-Options', 'SAMEORIGIN');
        $set('Content-Security-Policy', "frame-ancestors 'self'");
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
