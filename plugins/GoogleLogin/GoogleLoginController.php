<?php

namespace Plugins\GoogleLogin;

use App\Plugins\PluginManager;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * "Continue with Google": off to Google's consent page, and back (GoogleOAuth → GoogleSignIn), on the client area's
 * login or (when the settings let admins) /admin's, each with its own return address.
 */
class GoogleLoginController
{
    private const STATE = 'google_login.state';

    public function redirect(GoogleOAuth $google): RedirectResponse
    {
        $this->ensureAvailable();

        if (Filament::auth()->check()) {
            return redirect()->to(Filament::getUrl());
        }

        $state = Str::random(40);
        session()->put(self::STATE, $state);

        return redirect()->away($google->authorizationUrl($state, $this->panel()));
    }

    public function callback(Request $request, GoogleOAuth $google, GoogleSignIn $signIn): RedirectResponse
    {
        $this->ensureAvailable();
        $expected = (string) session()->pull(self::STATE);

        if ($request->filled('error')) { // e.g. "Cancel" on Google's page
            return $this->back(__('Google sign-in was cancelled.'));
        }

        if ($expected === '' || ! hash_equals($expected, (string) $request->query('state')) || blank($request->query('code'))) {
            return $this->back(__('The Google sign-in expired. Please try again.'));
        }

        try {
            $profile = $google->profile((string) $request->query('code'), $this->panel());

            return redirect()->to($this->panel() === 'admin' ? $signIn->handleAdmin($profile) : $signIn->handle($profile));
        } catch (GoogleLoginException $e) {
            return $this->back($e->getMessage());
        }
    }

    /** Only while the plugin is on and set up (and lets admins in, on /admin). */
    private function ensureAvailable(): void
    {
        abort_unless(app(PluginManager::class)->enabled('google_login') && GoogleOAuth::configured()
            && ($this->panel() !== 'admin' || GoogleOAuth::forAdmins()), 404);
    }

    private function panel(): string
    {
        return Filament::getCurrentOrDefaultPanel()->getId();
    }

    private function back(string $message): RedirectResponse
    {
        Notification::make()->title($message)->danger()->persistent()->send();

        return redirect()->to(Filament::getPanel($this->panel())->getLoginUrl());
    }
}
