<?php

namespace App\Http\Middleware;

use App\Support\LoginSecurity;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Settings → Login security → Admin panel addresses: when the list is set, every /admin request (login, pages,
 * Livewire updates) from another address gets a plain 403, signed in or not. The client area, the public pages, the
 * REST API and webhooks are not affected. `php artisan gaiacp:admin-ips --clear` empties the list from SSH.
 */
class RestrictAdminIps
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(LoginSecurity::admitsToAdmin($request->ip()), 403);

        return $next($request);
    }
}
