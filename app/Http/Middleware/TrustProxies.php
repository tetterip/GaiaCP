<?php

namespace App\Http\Middleware;

use App\Support\Cloudflare;
use Illuminate\Http\Middleware\TrustProxies as Middleware;

/**
 * Laravel's TrustProxies with the proxies read from config at request time (config is not loaded yet when
 * bootstrap/app.php runs): Cloudflare's own addresses unless `gaiacp.proxies.cloudflare` is off, plus any in
 * `gaiacp.proxies.trusted`. Behind them $request->ip() is the visitor's address and https:// URLs stay https,
 * so login throttling, sessions and the panel one-click login see the real visitor. A request from any other
 * address keeps its forwarded headers ignored, so nobody can fake their IP.
 */
class TrustProxies extends Middleware
{
    protected function proxies()
    {
        $proxies = (array) config('gaiacp.proxies.trusted', []);

        if (config('gaiacp.proxies.cloudflare')) {
            $proxies = [...$proxies, ...Cloudflare::IP_RANGES];
        }

        return $proxies ?: null;
    }
}
