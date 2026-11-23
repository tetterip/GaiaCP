<?php

namespace App\Filament\Clusters\Concerns;

use Closure;
use Illuminate\Support\Facades\App;

/**
 * The card grids of Settings and Reports (view filament.settings-overview): the search box matches a card's texts in
 * the admin's language and in English, so English terms ("plugin", "email") find it whatever language the panel is in.
 */
trait SearchesCardsInEnglishToo
{
    /** @param  Closure(): string  $texts  the card's group, name and description, translated when called */
    protected static function inAdminLanguageAndEnglish(Closure $texts): string
    {
        $locale = App::getLocale();
        $search = $texts();

        if ($locale === 'en') {
            return $search;
        }

        App::setLocale('en');

        try {
            return $search.' '.$texts();
        } finally {
            App::setLocale($locale);
        }
    }
}
