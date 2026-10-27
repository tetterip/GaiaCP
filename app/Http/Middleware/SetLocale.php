<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Locales;
use App\Support\PublicArea;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The panel in the signed-in user's language (Profile; a client user's falls back to their client's), else the one a
 * visitor picked in the client area's top bar (PublicArea::LOCALE), else the default one.
 */
class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = filament()->getCurrentPanel() ? filament()->auth()->user() : $request->user();

        app()->setLocale($user instanceof User ? $user->preferredLocale() : Locales::normalize($request->hasSession() ? $request->session()->get(PublicArea::LOCALE) : null));

        return $next($request);
    }
}
