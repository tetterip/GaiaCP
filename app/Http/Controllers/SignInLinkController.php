<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\SignIn\SignIn;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * A sign-in link from the email (App\SignIn\EmailLinks), on either panel: checked and marked pending, then on to the
 * login page, where pressing Log in uses it. Nobody is logged in by opening it, and a browser already logged in
 * there stays as it is.
 */
class SignInLinkController
{
    public function __invoke(Request $request): RedirectResponse
    {
        $panel = Filament::getCurrentPanel();
        $user = User::query()->find($request->query('user'));

        if (! SignIn::emailLinks($panel->getId()) || ! $request->hasValidSignature() || $user === null || ! $user->canAccessPanel($panel) || blank($request->query('nonce'))) {
            Notification::make()->title(__('This sign-in link has expired or was already used. Ask for a new one.'))->danger()->persistent()->send();

            return redirect()->to($panel->getLoginUrl());
        }

        if ($panel->auth()->check()) {
            return redirect()->to($panel->getUrl()); // already in: the link stays unused
        }

        SignIn::begin($user, $panel->getId(), SignIn::EMAIL_LINK, ['nonce' => (string) $request->query('nonce')]);

        return redirect()->to($panel->getLoginUrl());
    }
}
