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

        // Back to the page, without a `?lang=` of its own that would win over the language just picked
        $previous = url()->previous(filament()->getUrl());

        return redirect()->to(Seo::withoutLanguage($previous));
    }
}
