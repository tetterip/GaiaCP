<?php

namespace App\Http\Middleware;

use App\Support\LoginSecurity;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A panel's pages for visitors who aren't signed in (login, sign up, forgot password, sign-in plugins' routes) answer
 * a banned address with the ban page instead (LoginSecurity). Signed-in users are never shut out by it.
 */
class BlockBannedIps
{
    public function handle(Request $request, Closure $next): Response
    {
        if (filament()->getCurrentPanel() && filament()->auth()->check()) {
            return $next($request);
        }

        $ban = LoginSecurity::banFor($request->ip());

        if ($ban === null) {
            return $next($request);
        }

        return response()->view('auth.ip-banned', ['ban' => $ban, 'ip' => $request->ip()], 403);
    }
}
