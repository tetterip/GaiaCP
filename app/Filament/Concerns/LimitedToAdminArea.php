<?php

namespace App\Filament\Concerns;

use App\Enums\AdminArea;
use App\Models\User;
use App\Support\Demo;

/**
 * A page or resource that admins open only with its area ticked on their user (super admins always). Hidden from
 * the menu, search and Settings cards, and refused by URL, through Filament's canAccess(); the demo closes some to
 * everyone (App\Support\Demo::CLOSED). A class with its own
 * canAccess() calls canUseAdminArea() in it.
 */
trait LimitedToAdminArea
{
    /** null = super admins only. */
    public static function adminArea(): ?AdminArea
    {
        return null;
    }

    public static function canAccess(): bool
    {
        return static::canUseAdminArea() && parent::canAccess();
    }

    protected static function canUseAdminArea(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->canUseArea(static::adminArea()) && ! Demo::closes(static::class);
    }
}
