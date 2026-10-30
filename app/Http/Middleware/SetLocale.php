<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Locales;
use App\Support\PublicArea;
use App\Support\Seo;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The panel in the signed-in user's language (Profile; a client user's falls back to their client's), else the one a
 * visitor picked: in the address (`?lang=el`, how search engines reach each language, Seo) or in the client area's
 * top bar, kept for their session (PublicArea::LOCALE), else the default one.
 */
class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = filament()->getCurrentPanel() ? filament()->auth()->user() : $request->user();

        if ($user instanceof User) {
            app()->setLocale($user->preferredLocale());

            return $next($request);
        }

        $asked = $request->query(Seo::LANGUAGE);
        $locale = is_string($asked) && array_key_exists($asked, Locales::available()) ? $asked : null;

        if ($request->hasSession() && $locale !== null) {
            $request->session()->put(PublicArea::LOCALE, $locale);
        } elseif ($request->hasSession()) {
            $locale = $request->session()->get(PublicArea::LOCALE);
        }

        app()->setLocale(Locales::normalize($locale));

        return $next($request);
    }
}
