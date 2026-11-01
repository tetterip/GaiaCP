<?php

namespace Plugins\GoogleLogin;

use App\Actions\ClientAreaAccess;
use App\Actions\ClientSignup;
use App\Models\Client;
use App\Models\User;
use App\SignIn\SignIn;
use Filament\Facades\Filament;
use Illuminate\Support\Str;

/**
 * What a Google account Google has confirmed opens in the client area, matched by email address (case ignored):
 * - a login with that address that can open the client area is logged in (its address counts as confirmed);
 * - a client with that address and no login gets one (ClientAreaAccess::connect()) and is logged in;
 * - an address unknown here goes on to the sign up form, if new clients can sign up.
 * Never a duplicate client. Refused: an unconfirmed Google address, admins who aren't clients and a login turned off.
 * A login with two-factor authentication on gives its code on the login page next (SignIn::begin()). Logged.
 *
 * On /admin (handleAdmin(), when the plugin's settings let admins): only an existing active admin with that address,
 * never a new one; two-factor authentication is asked the same way.
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

        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified(); // Google confirmed it
        }

        return $this->logIn($user, 'client');
    }

    /**
     * @param  array{email: string, email_verified: bool}  $profile
     * @return string where to go next
     *
     * @throws GoogleLoginException
     */
    public function handleAdmin(array $profile): string
    {
        $email = Str::lower(trim($profile['email']));

        if (! $profile['email_verified']) {
            throw new GoogleLoginException(__('Google has not confirmed :email, so it can\'t be used to log in.', ['email' => $email]));
        }

        $user = User::query()->whereRaw('lower(email) = ?', [$email])->first();

        if ($user === null || ! $user->canAccessPanel(Filament::getPanel('admin'))) {
            throw new GoogleLoginException(__('No admin who can log in uses :email.', ['email' => $email]));
        }

        return $this->logIn($user, 'admin');
    }

    /** Logged in now, or on to the login page for the two-factor code. */
    private function logIn(User $user, string $panel): string
    {
        if (SignIn::needsTwoFactor($user, $panel)) {
            SignIn::begin($user, $panel, SignIn::GOOGLE);

            return Filament::getPanel($panel)->getLoginUrl();
        }

        SignIn::complete($user, $panel, SignIn::GOOGLE);

        return session()->pull('url.intended', Filament::getPanel($panel)->getUrl());
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
