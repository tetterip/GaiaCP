<?php

namespace Plugins\GoogleLogin;

use App\Actions\ClientAreaAccess;
use App\Actions\ClientSignup;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * What a Google account Google has confirmed opens in the client area, matched by email address (case ignored):
 * - a login with that address that can open the client area is logged in (its address counts as confirmed);
 * - a client with that address and no login gets one (ClientAreaAccess::connect()) and is logged in;
 * - an address unknown here goes on to the sign up form, if new clients can sign up.
 * Never a duplicate client. Refused: an unconfirmed Google address, admins who aren't clients, a login turned off,
 * and logins with two-factor authentication on (they log in with their password and code instead). Logged.
 */
class GoogleSignIn
{
    public function __construct(private ClientAreaAccess $access) {}

    /**
     * @param  array{email: string, email_verified: bool, given_name?: ?string, family_name?: ?string, name?: ?string}  $profile
     * @return string where to go next
     *
     * @throws GoogleLoginException
     */
    public function handle(array $profile): string
    {
        $email = Str::lower(trim($profile['email']));
        $panel = Filament::getPanel('client');

        if (! $profile['email_verified']) {
            throw new GoogleLoginException(__('Google has not confirmed :email, so it can\'t be used to log in.', ['email' => $email]));
        }

        $user = User::query()->whereRaw('lower(email) = ?', [$email])->first() ?? $this->clientLogin($email);

        if ($user === null) {
            if (! ClientSignup::enabled()) {
                throw new GoogleLoginException(__('No client account uses :email. Log in with the address you gave us, or contact us.', ['email' => $email]));
            }

            ClientSignup::continueWith('Google', [
                'email' => $email,
                'first_name' => $profile['given_name'] ?? null,
                'last_name' => $profile['family_name'] ?? null,
            ]);

            return $panel->getRegistrationUrl();
        }

        if (! $user->canAccessPanel($panel)) {
            throw new GoogleLoginException(__(':email can\'t log in to the client area. Please contact us.', ['email' => $email]));
        }

        if (filled($user->getAppAuthenticationSecret())) {
            throw new GoogleLoginException(__('Two-factor authentication is on for :email: log in with your password and code instead.', ['email' => $email]));
        }

        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified(); // Google confirmed it
        }

        Auth::guard('client')->login($user);
        session()->regenerate();
        ActivityLog::record('client_area.google_login', "{$user->email} logged in to the client area with Google", client: $user->client);

        return session()->pull('url.intended', $panel->getUrl());
    }

    /** The login of the one client with this address, made now when it has none (null: no such client). */
    private function clientLogin(string $email): ?User
    {
        $clients = Client::query()->whereRaw('lower(email) = ?', [$email])->limit(2)->get();

        if ($clients->count() > 1) {
            throw new GoogleLoginException(__('More than one client uses :email. Please contact us.', ['email' => $email]));
        }

        return $clients->isEmpty() ? null : $this->access->connect($clients->first(), 'Google');
    }
}
