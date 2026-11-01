<?php

namespace App\SignIn;

use App\Models\ActivityLog;
use App\Models\User;
use App\SignIn\Passkeys\Passkeys;
use App\Support\Demo;
use Filament\Auth\MultiFactor\MultiFactorChallenge;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Cookie;

/**
 * The ways in on both logins besides the password: Google (plugin), passkeys (Passkeys) and a link by email
 * (EmailLinks). A way that proves only the email address (Google, a link) still asks for the two-factor code when the
 * login has it on: it is kept here as "pending" and the login page asks for the code (QuickSignIn). The browser
 * remembers the last way used on each login, which the login page marks "Last used".
 */
class SignIn
{
    public const PASSWORD = 'password';

    public const GOOGLE = 'google';

    public const PASSKEY = 'passkey';

    public const EMAIL_LINK = 'email_link';

    private const PENDING = 'sign_in.pending';

    private const PENDING_SECONDS = 600;

    /** Shown as "Last used" for a year and some, as most sites do. */
    private const COOKIE_MINUTES = 60 * 24 * 400;

    /** Whether the login of $panel (admin, client) offers passkeys. */
    public static function passkeys(string $panel): bool
    {
        return Passkeys::enabled();
    }

    /** Whether the login of $panel offers "Email me a sign-in link" (Settings → Login security). */
    public static function emailLinks(string $panel): bool
    {
        return ! Demo::enabled() && (bool) config($panel === 'admin' ? 'gaiacp.sign_in.admin_email_link' : 'gaiacp.sign_in.email_link');
    }

    /** The way this browser last logged in to $panel (one of the constants), if any. */
    public static function lastUsed(string $panel): ?string
    {
        $method = request()->cookie(self::cookie($panel));

        return is_string($method) && in_array($method, [self::PASSWORD, self::GOOGLE, self::PASSKEY, self::EMAIL_LINK], true) ? $method : null;
    }

    public static function remember(string $panel, string $method): void
    {
        // Made first: queue() drops argument names, and a path left out would be the Livewire request's
        Cookie::queue(Cookie::make(self::cookie($panel), $method, self::COOKIE_MINUTES));
    }

    public static function cookie(string $panel): string
    {
        return 'gaiacp_last_sign_in_'.$panel;
    }

    /** Whether $user has to give a two-factor code on $panel (the panel's own providers, as on the password login). */
    public static function needsTwoFactor(User $user, string $panel): bool
    {
        $previous = Filament::getCurrentPanel();
        Filament::setCurrentPanel(Filament::getPanel($panel));

        try {
            return MultiFactorChallenge::make()->hasEnabledProviders($user);
        } finally {
            Filament::setCurrentPanel($previous);
        }
    }

    /** Logs $user in to $panel now, $method remembered as "Last used" and logged. */
    public static function complete(User $user, string $panel, string $method): void
    {
        self::forget();
        Filament::getPanel($panel)->auth()->login($user);
        session()->regenerate();
        self::remember($panel, $method);

        $where = $panel === 'admin' ? 'the admin panel' : 'the client area';
        $how = match ($method) {
            self::GOOGLE => 'with Google',
            self::PASSKEY => 'with a passkey',
            self::EMAIL_LINK => 'with a link sent by email',
            default => 'with a password',
        };

        ActivityLog::record("sign_in.{$method}", "{$user->email} logged in to {$where} {$how}", client: $panel === 'client' ? $user->client : null);
    }

    /**
     * $user proved their email address ($method) and goes on at $panel's login page: for the two-factor code, or (a
     * link) to press Log in there, so a mail scanner that opens the link logs nobody in.
     *
     * @param  array<string, mixed>  $extra
     */
    public static function begin(User $user, string $panel, string $method, array $extra = []): void
    {
        session()->put(self::PENDING, $extra + [
            'user_id' => $user->getKey(),
            'user_key' => self::userKey($user),
            'panel' => $panel,
            'method' => $method,
            'expires' => time() + self::PENDING_SECONDS,
        ]);
    }

    /**
     * The pending sign-in on $panel and its user, while it is fresh and the login is unchanged (same email, password,
     * still allowed in).
     *
     * @return array{user: User, method: string, nonce?: string}|null
     */
    public static function pending(string $panel): ?array
    {
        $pending = session(self::PENDING);

        if (! is_array($pending) || ($pending['panel'] ?? null) !== $panel || ($pending['expires'] ?? 0) < time()) {
            return null;
        }

        $user = User::query()->find($pending['user_id'] ?? null);

        if ($user === null || ! hash_equals((string) ($pending['user_key'] ?? ''), self::userKey($user)) || ! $user->canAccessPanel(Filament::getPanel($panel))) {
            return null;
        }

        return ['user' => $user, 'method' => (string) $pending['method']] + (isset($pending['nonce']) ? ['nonce' => (string) $pending['nonce']] : []);
    }

    /** Changes when the login's email address or password does. */
    private static function userKey(User $user): string
    {
        return hash('sha256', $user->getKey().'|'.$user->email.'|'.$user->getAuthPassword());
    }

    public static function forget(): void
    {
        session()->forget(self::PENDING);
    }
}
