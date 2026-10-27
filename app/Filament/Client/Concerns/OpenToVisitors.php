<?php

namespace App\Filament\Client\Concerns;

use Filament\Http\Middleware\Authenticate;
use Filament\Panel;

/**
 * A client area page that visitors also open without logging in (the public area, App\Support\PublicArea): its route
 * skips the login, the email confirmation and the two-factor step. canAccess() says when it is open to whom.
 */
trait OpenToVisitors
{
    /** @return array<string> */
    public static function getWithoutRouteMiddleware(Panel $panel): array
    {
        return [Authenticate::class];
    }

    public static function isEmailVerificationRequired(Panel $panel): bool
    {
        return false;
    }

    public static function isMultiFactorAuthenticationRequired(Panel $panel): bool
    {
        return false;
    }

    /** Whether nobody is logged in to the client area. */
    protected static function visitor(): bool
    {
        return ! filament()->auth()->check();
    }
}
