<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\LoginSecurity;
use Closure;
use Filament\Auth\MultiFactor\Http\Middleware\EnsureMultiFactorAuthenticationIsEnabled;
use Illuminate\Http\Request;

/**
 * Settings → Login security → Require two-factor authentication. Filament adds this middleware to every /admin page
 * when the panel requires multi-factor authentication, which it always does (outside the demo) so the route list
 * stays the same whatever the setting (routes can be cached); the setting is read here, on each request. An admin it
 * applies to without two-factor on is sent to Filament's set-up page until they turn it on.
 */
class RequireAdminTwoFactor extends EnsureMultiFactorAuthenticationIsEnabled
{
    public function handle(Request $request, Closure $next): mixed
    {
        $user = filament()->auth()->user();

        if (! $user instanceof User || ! LoginSecurity::requiresTwoFactor($user)) {
            return $next($request);
        }

        return parent::handle($request, $next);
    }
}
