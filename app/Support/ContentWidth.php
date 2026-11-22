<?php

namespace App\Support;

use Filament\Support\Enums\Width;
use Filament\Tables\Contracts\HasTable;
use Illuminate\Support\Facades\Route;

/**
 * How wide a panel page's content is (both panels, `maxContentWidth()`): a page whose content is a table (Clients,
 * Hosting, Payments, reports…) uses the whole width, so its columns are not squeezed; the rest (Home, forms, custom
 * pages) keep Filament's default. A page that sets its own `$maxContentWidth` keeps it.
 */
class ContentWidth
{
    public static function current(): ?Width
    {
        $page = Route::current()?->getControllerClass();

        return $page !== null && is_subclass_of($page, HasTable::class) ? Width::Full : null;
    }
}
