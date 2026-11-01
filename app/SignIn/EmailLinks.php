<?php

namespace App\SignIn;

use App\Models\ActivityLog;
use App\Models\User;
use App\Notifications\SignInLink;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * "Email me a sign-in link" on the login pages (Settings → Login security): a signed link, valid for MINUTES and
 * once. Opening it only marks the sign-in pending (SignIn::begin()); the login page then asks to press Log in (and
 * for the two-factor code when it is on), so a mail scanner that opens links logs nobody in. The answer on the login
 * page is the same whether or not the address can log in, and each address gets at most 3 links in 15 minutes.
 */
class EmailLinks
{
    public const MINUTES = 15;

    private const PER_ADDRESS = 3;

    private const PER_IP = 10;

    /** Emails a link to $email if it is a login that can open $panel (admin, client); silent otherwise. */
    public function send(string $email, string $panel): void
    {
        $email = Str::lower(trim($email));

        if (! SignIn::emailLinks($panel)
            || RateLimiter::tooManyAttempts($addressKey = 'sign-in-link:'.sha1($email), self::PER_ADDRESS)
            || RateLimiter::tooManyAttempts($ipKey = 'sign-in-link-ip:'.request()->ip(), self::PER_IP)) {
            return;
        }

        RateLimiter::hit($addressKey, self::MINUTES * 60);
        RateLimiter::hit($ipKey, self::MINUTES * 60);

        $user = User::query()->whereRaw('lower(email) = ?', [$email])->first();

        if ($user === null || ! $user->canAccessPanel(Filament::getPanel($panel))) {
            return;
        }

        $user->notify(new SignInLink($this->url($user, $panel), $panel === 'client'));
        ActivityLog::record('sign_in.link_sent', "Sign-in link emailed to {$user->email} for ".($panel === 'admin' ? 'the admin panel' : 'the client area'), client: $panel === 'client' ? $user->client : null);
    }

    public function url(User $user, string $panel): string
    {
        return URL::temporarySignedRoute("filament.{$panel}.sign-in-link", now()->addMinutes(self::MINUTES), [
            'user' => $user->getKey(),
            'nonce' => Str::random(40),
        ]);
    }

    /** Uses up a link's nonce: true the first time only. */
    public static function use(string $nonce): bool
    {
        return Cache::add('sign-in-link:'.hash('sha256', $nonce), true, now()->addMinutes(self::MINUTES + 5));
    }
}
