<?php

namespace App\Http\Middleware;

use Filament\Facades\Filament;
use Filament\Http\Middleware\Authenticate as FilamentAuthenticate;
use Filament\Models\Contracts\FilamentUser;

/**
 * Filament's Authenticate for both panels, except that a browser still signed in to a login that can no longer open
 * the panel (client area login turned off, admin unlinked from the client, admin user turned off) is signed out of
 * that panel and sent to its login page, instead of a 403 on every page until the session ends.
 */
class Authenticate extends FilamentAuthenticate
{
    protected function authenticate($request, array $guards): void
    {
        $guard = Filament::auth();
        $user = $guard->user();

        if ($user instanceof FilamentUser && ! $user->canAccessPanel(Filament::getCurrentOrDefaultPanel())) {
            $guard->logout(); // this panel's guard only: the other panel stays signed in

            $this->unauthenticated($request, $guards);
        }

        parent::authenticate($request, $guards);
    }
}
