<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustHosts as Middleware;
use Illuminate\Http\Request;

/**
 * Answers only for the install's own address: APP_URL's host and its subdomains (without its www. too), plus any in `gaiacp.trusted_hosts`
 * (GAIACP_TRUSTED_HOSTS). Links in emails (password reset, sign-in links, email confirmation) are made from the
 * request's host, so a request for another name (a catch-all virtual host, a forged Host header) would otherwise
 * email a victim a link to the attacker's site with a working token; such a request gets a 400 instead.
 * Left off while APP_URL is still a local address (before the installer, `php artisan serve`), so a fresh copy
 * always opens.
 */
class TrustHosts extends Middleware
{
    public function hosts()
    {
        $hosts = array_map(
            fn (string $host) => '^'.preg_quote(strtolower($host)).'$',
            array_filter(array_map('trim', (array) config('gaiacp.trusted_hosts', []))),
        );

        // APP_URL on www.example.com: example.com (and its other subdomains) is the same site
        $host = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));
        if (str_starts_with($host, 'www.')) {
            $hosts[] = '^(.+\.)?'.preg_quote(substr($host, 4)).'$';
        }

        return [...$hosts, $this->allSubdomainsOfApplicationUrl()];
    }

    public function handle(Request $request, $next)
    {
        // Request::setTrustedHosts() is static: always set, so a check made for one request never lingers into the next
        Request::setTrustedHosts($this->shouldSpecifyTrustedHosts() ? array_values(array_filter($this->hosts())) : []);

        return $next($request);
    }

    protected function shouldSpecifyTrustedHosts()
    {
        $host = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));

        return $host !== '' && ! in_array($host, ['localhost', '127.0.0.1', '[::1]', '::1', '0.0.0.0'], true) && ! str_ends_with($host, '.localhost');
    }
}
