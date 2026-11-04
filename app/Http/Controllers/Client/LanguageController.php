<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Support\Locales;
use App\Support\PublicArea;
use App\Support\Seo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** A visitor's language from the client area's top bar (SetLocale reads it), kept for their session. */
class LanguageController extends Controller
{
    public function __invoke(Request $request, string $locale): RedirectResponse
    {
        abort_unless(array_key_exists($locale, Locales::available()), 404);

        $request->session()->put(PublicArea::LOCALE, $locale);

        // Back to the page, without a `?lang=` of its own that would win over the language just picked. The previous
        // page is the browser's Referer: only one of this install (same address and folder), never another site.
        $home = filament()->getUrl();
        $previous = url()->previous($home);
        $root = rtrim(url('/'), '/');
        if ($previous !== $root && ! str_starts_with($previous, $root.'/') && ! str_starts_with($previous, $root.'?')) {
            $previous = $home;
        }

        return redirect()->to(Seo::withoutLanguage($previous));
    }
}
