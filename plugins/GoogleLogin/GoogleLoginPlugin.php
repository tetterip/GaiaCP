<?php

namespace Plugins\GoogleLogin;

use App\Plugins\Plugin;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\HtmlString;

/**
 * "Continue with Google" on the client area's login and sign up pages and, when its settings let admins, /admin's
 * login (GoogleSignIn), set up under Settings → Google sign-in.
 */
class GoogleLoginPlugin extends Plugin
{
    /** Google's "G" mark, as Google's sign-in branding asks. */
    private const LOGO = '<svg viewBox="0 0 48 48" aria-hidden="true"><path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/><path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/><path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/><path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/></svg>';

    public function id(): string
    {
        return 'google_login';
    }

    public function name(): string
    {
        return __('Google sign-in');
    }

    public function description(): string
    {
        return __('Clients log in or sign up in the client area with their Google account.');
    }

    public function category(): string
    {
        return 'Integrations';
    }

    public function settingsPage(): ?string
    {
        return GoogleLoginSettings::class;
    }

    public function pages(): array
    {
        return [GoogleLoginSettings::class];
    }

    public function settingKeys(): array
    {
        return ['google_login.client_id', 'google_login.client_secret', 'google_login.admins'];
    }

    public function encryptedSettingKeys(): array
    {
        return ['google_login.client_secret'];
    }

    public function moduleLogs(): array
    {
        return ['google_login' => 'Google sign-in'];
    }

    public function clientRoutes(): void
    {
        Route::get('auth/google', [GoogleLoginController::class, 'redirect'])->name('google-login.redirect');
        Route::get('auth/google/callback', [GoogleLoginController::class, 'callback'])->name('google-login.callback');
    }

    public function adminRoutes(): void
    {
        $this->clientRoutes(); // the same addresses under /admin
    }

    public function clientLoginButtons(): array
    {
        return GoogleOAuth::configured() ? [$this->button('client')] : [];
    }

    public function adminLoginButtons(): array
    {
        return GoogleOAuth::configured() && GoogleOAuth::forAdmins() ? [$this->button('admin')] : [];
    }

    private function button(string $panel): HtmlString
    {
        return new HtmlString(view('filament.client.sign-in-button', [
            'url' => route("filament.{$panel}.google-login.redirect"),
            'label' => __('Continue with Google'),
            'icon' => self::LOGO,
            'method' => 'google',
        ])->render());
    }
}
