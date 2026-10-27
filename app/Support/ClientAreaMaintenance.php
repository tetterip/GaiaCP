<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * The client area's maintenance mode (Settings → Client area, gaiacp.client_area.maintenance*), like WHMCS's: while it is
 * on, App\Http\Middleware\ClientAreaMaintenance answers every client area page with the message, or sends the visitor
 * to the redirect address. Admins signed in to /admin still get in (to check things with "Log in as client").
 */
class ClientAreaMaintenance
{
    public static function isOn(): bool
    {
        return (bool) config('gaiacp.client_area.maintenance');
    }

    /** The admin's own message, else the built-in one in the visitor's language. */
    public static function message(): string
    {
        $message = trim((string) config('gaiacp.client_area.maintenance_message'));

        return $message !== '' ? $message : self::defaultMessage();
    }

    public static function defaultMessage(): string
    {
        return __('We are carrying out maintenance on the client area. Please try again shortly.');
    }

    public static function redirectUrl(): ?string
    {
        $url = trim((string) config('gaiacp.client_area.maintenance_redirect'));

        return $url !== '' ? $url : null;
    }

    /** An active admin signed in to /admin (the web guard) passes, whatever client they are signed in as. */
    public static function lets(): bool
    {
        $admin = Auth::guard('web')->user();

        return $admin instanceof User && $admin->active && $admin->role->canAccessAdminPanel();
    }

    /** True for an address on this site, which would send the visitor straight back to the maintenance page. */
    public static function pointsHere(string $url, Request $request): bool
    {
        return strcasecmp((string) parse_url($url, PHP_URL_HOST), $request->getHost()) === 0;
    }
}
