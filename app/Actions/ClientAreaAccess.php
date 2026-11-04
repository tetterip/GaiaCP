<?php

namespace App\Actions;

use App\Enums\MailTemplate;
use App\Enums\UserRole;
use App\Mail\ClientMailer;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\ClientSession;
use App\Models\User;
use App\Notifications\ResetAdminPassword;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use RuntimeException;
use SensitiveParameter;

/**
 * A client's login to the client area: invite (creates the login if needed and emails a link to choose a
 * password), create it with a password when adding the client, set its password or email a reset link, turn it off or on, and let an admin look at the client
 * area as that client. An admin user with the client's email is linked to the client instead (users.client_id): one
 * login for /admin and the client area. Logged.
 */
class ClientAreaAccess
{
    /** Session key: the client user an admin is looking at the client area as (logInAs()), for the bar and its way back. */
    public const VIEWING_AS = 'gaiacp.viewing_client_area_as';

    public function __construct(private ClientMailer $mailer) {}

    /**
     * @return bool whether the invitation email went out (false too for an admin linked instead: they log in with
     *              their admin password, so there is no email)
     *
     * @throws RuntimeException when the client has no email or another user already has it
     */
    public function invite(Client $client): bool
    {
        $user = $this->userFor($client);

        if ($user->role !== UserRole::Client) {
            return false;
        }

        // Inviting gives access: also to a login made only for an admin to look (logInAs()) or one turned off
        if (! $user->active) {
            $user->update(['active' => true]);
        }

        $panel = Filament::getPanel('client');
        $link = $panel->getResetPasswordUrl(Password::broker('clients')->createToken($user), $user);

        $sent = $this->mailer->send(MailTemplate::ClientAreaInvite, $client, [
            'client_area_url' => $panel->getLoginUrl(),
            'set_password_url' => $link,
        ], evenIfOff: true);

        ActivityLog::record('client_area.invited', "Client area invitation for {$client->email}".($sent ? '' : ' (email not sent)'), client: $client);

        return $sent;
    }

    /**
     * A login with the password the admin chose when adding the client, emailed with the Client Area Login Details
     * template when asked. An admin with the client's email is linked instead: their password stays, nothing is emailed.
     *
     * @return User the login
     *
     * @throws RuntimeException when the client has no email or another client's user already has it
     */
    public function createLogin(Client $client, #[SensitiveParameter] string $password, bool $email): User
    {
        $user = $this->userFor($client, $password);

        if ($user->role !== UserRole::Client) {
            return $user;
        }

        ActivityLog::record('client_area.login_created', "Client area login created for {$client->email}", client: $client);

        if ($email) {
            $this->mailer->send(MailTemplate::ClientAreaLogin, $client, [
                'client_area_url' => Filament::getPanel('client')->getLoginUrl(),
                'password' => $password,
            ], evenIfOff: true);
        }

        return $user;
    }

    /**
     * An admin sets the client's password: a client without a login gets one (turned on), an existing one keeps
     * whether it is on or off. Every browser signed in with the old password is signed out. Emailed with the Client
     * Area Login Details template when asked. Never an admin user linked to the client: their password stays theirs.
     *
     * @return bool whether the email went out (false when not asked for)
     *
     * @throws RuntimeException when the client has no email, another user already has it, or it is a linked admin
     */
    public function setPassword(Client $client, #[SensitiveParameter] string $password, bool $email): bool
    {
        if (self::isLinkedAdmin($client) || self::adminWithEmail($client)) {
            throw new RuntimeException(__('This login is an admin user: only Settings → Admin users can change its password.'));
        }

        if ($client->user === null) {
            $this->createLogin($client, $password, false);
        } else {
            $client->user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
            // AuthenticateSession signs out the browsers with the old password; these rows go with them.
            ClientSession::query()->where('user_id', $client->user->id)->delete();
            ActivityLog::record('client_area.password_set', "Client area password of {$client->email} set by an admin", client: $client);
        }

        if (! $email) {
            return false;
        }

        return $this->mailer->send(MailTemplate::ClientAreaLogin, $client, [
            'client_area_url' => Filament::getPanel('client')->getLoginUrl(),
            'password' => $password,
        ], evenIfOff: true);
    }

    /**
     * Emails the client the same link as the login's "Forgot password?" (it works once), saying their provider sent
     * it. Only to a client login that is turned on: inviting is for the others.
     *
     * @throws RuntimeException when the client has no login that is turned on
     */
    public function sendPasswordResetLink(Client $client): void
    {
        $user = $client->user;

        if ($user === null || $user->role !== UserRole::Client || ! $user->active) {
            throw new RuntimeException(__('The client has no client area login that is turned on: invite them instead.'));
        }

        $token = Password::broker('clients')->createToken($user);
        $notification = new ResetAdminPassword($token, byAdmin: true);
        $notification->url = Filament::getPanel('client')->getResetPasswordUrl($token, $user);
        $user->notify($notification);

        ActivityLog::record('client_area.password_reset_sent', "Password reset link sent to {$client->email} by an admin", client: $client);
    }

    /** Whether the client has no login yet and an admin user logs in with its email (inviting would link them). */
    public static function adminWithEmail(Client $client): bool
    {
        return $client->user === null && filled($client->email)
            && User::query()->where('email', $client->email)->where('role', '!=', UserRole::Client)->whereNull('client_id')->exists();
    }

    /**
     * The login of a client who proved they own its email address with a sign-in provider (e.g. Google): the
     * existing one, or a new one with a random password (they log in through the provider, or set one with
     * "Forgot password?"). Nothing is emailed.
     */
    public function connect(Client $client, string $provider): User
    {
        $existing = $client->user;
        $user = $this->userFor($client);

        if ($existing === null && $user->role === UserRole::Client) {
            ActivityLog::record('client_area.login_created', "Client area login created for {$client->email} through {$provider}", client: $client);
        }

        return $user;
    }

    /** Whether this client's login is an admin user linked to it (one login for /admin and the client area). */
    public static function isLinkedAdmin(Client $client): bool
    {
        return $client->user !== null && $client->user->role !== UserRole::Client;
    }

    /** The client's login: the existing one, an admin with the same email (linked), or a new client user. */
    private function userFor(Client $client, #[SensitiveParameter] ?string $password = null): User
    {
        if (blank($client->email)) {
            throw new RuntimeException(__('The client has no email address.'));
        }

        if ($client->user) {
            return $client->user;
        }

        $existing = User::query()->where('email', $client->email)->first();

        if ($existing && ($existing->role === UserRole::Client || $existing->client_id !== null)) {
            throw new RuntimeException(__('Another user already logs in with :email.', ['email' => $client->email]));
        }

        if ($existing) {
            $existing->update(['client_id' => $client->id]);
            ActivityLog::record('client_area.admin_linked', "Admin user {$existing->email} linked to the client: one login for both panels", client: $client);
            $client->setRelation('user', $existing);

            return $existing;
        }

        $user = new User([
            'name' => $client->name,
            'email' => $client->email,
            'role' => UserRole::Client,
            'client_id' => $client->id,
            'password' => $password ?? Str::password(32), // without one, replaced when they follow the invitation link
        ]);
        $user->email_verified_at = now(); // an address an admin entered, or one a sign-in provider confirmed
        $user->save();
        $client->setRelation('user', $user);

        return $user;
    }

    /** Turns the login off; an admin linked to the client is unlinked instead, so their /admin login stays. */
    public function disable(Client $client): void
    {
        if (self::isLinkedAdmin($client)) {
            $email = $client->user->email;
            $client->user->update(['client_id' => null]);
            ActivityLog::record('client_area.admin_unlinked', "Admin user {$email} unlinked from the client", client: $client);

            return;
        }

        $client->user?->update(['active' => false]);
        ActivityLog::record('client_area.disabled', "Client area login of {$client->email} turned off", client: $client);
    }

    /** Turns a client's login on again. Never an admin's: only Settings → Admin users turns an admin back on. */
    public function enable(Client $client): void
    {
        if (self::isLinkedAdmin($client)) {
            throw new RuntimeException(__('This login is an admin user: only Settings → Admin users can turn it on.'));
        }

        $client->user?->update(['active' => true]);
        ActivityLog::record('client_area.enabled', "Client area login of {$client->email} turned on", client: $client);
    }

    /** Signs the admin in to the client area as this client (its own guard: /admin stays signed in). */
    /**
     * A client without a login gets one that is turned off, so only admins look through it until the client is
     * invited; a turned off login opens too while an admin looks (User::canAccessPanel()).
     */
    public function logInAs(Client $client): void
    {
        $user = $client->user;

        if ($user === null) {
            $user = $this->userFor($client);

            if ($user->role === UserRole::Client) {
                $user->update(['active' => false]);
                ActivityLog::record('client_area.login_created', "Client area login created for {$client->email}, turned off until the client is invited", client: $client);
            }
        }

        Auth::guard('client')->login($user);
        session()->put(self::VIEWING_AS, $user->id);
        ActivityLog::record('client_area.logged_in_as', "Admin opened the client area as {$client->email}", client: $client);
    }

    /** Whether the signed-in client user is an admin looking (logInAs()), not the client. */
    public static function viewing(): bool
    {
        $user = Auth::guard('client')->user();

        return $user !== null && session(self::VIEWING_AS) === $user->getAuthIdentifier() && Auth::guard('web')->check();
    }

    /** Signs the admin out of the client area again; /admin stays signed in. */
    public function stopViewing(): ?Client
    {
        $client = Auth::guard('client')->user()?->client;

        Auth::guard('client')->logout();
        session()->forget(self::VIEWING_AS);

        if ($client) {
            ActivityLog::record('client_area.left', "Admin left the client area of {$client->email}", client: $client);
        }

        return $client;
    }
}
