<?php

namespace App\Http\Middleware;

use App\Themes\Themes;
use Closure;
use Filament\Support\Facades\FilamentColor;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The admin panel in the signed-in admin's theme (App\Themes\Themes): its colours, registered after the session so
 * the admin is known (the panel's own colours are set before it). Fonts and the stylesheet are read at render.
 */
class ApplyTheme
{
    public function handle(Request $request, Closure $next): Response
    {
        FilamentColor::register(Themes::colors(Themes::current()));

        return $next($request);
    }
}
