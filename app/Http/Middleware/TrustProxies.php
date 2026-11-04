<?php

namespace App\Http\Middleware;

use App\Support\Cloudflare;
use Illuminate\Http\Middleware\TrustProxies as Middleware;
use Illuminate\Http\Request;

/**
 * Laravel's TrustProxies with the proxies read from config at request time (config is not loaded yet when
 * bootstrap/app.php runs): Cloudflare's own addresses unless `gaiacp.proxies.cloudflare` is off, plus any in
 * `gaiacp.proxies.trusted`. Behind them $request->ip() is the visitor's address and https:// URLs stay https,
 * so login throttling, sessions and the panel one-click login see the real visitor. A request from any other
 * address keeps its forwarded headers ignored, so nobody can fake their IP.
 *
 * Cloudflare keeps the visitor's Host and sends only X-Forwarded-For / -Proto, so from its addresses alone nothing
 * else is believed: an X-Forwarded-Host sent through it (or from a Worker) would otherwise put another site's name in
 * the links of password reset and sign-in emails. Proxies of `gaiacp.proxies.trusted` (a load balancer) may also set
 * the host, port and prefix; TrustHosts still allows only the install's own address.
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

    protected function headers()
    {
        if ((array) config('gaiacp.proxies.trusted', []) === []) {
            return Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO;
        }

        return parent::headers();
    }
}
