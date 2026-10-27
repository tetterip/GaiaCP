<?php

namespace App\Support;

use App\Models\ActivityLog;
use App\Models\IpBan;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Bans an IP address after too many failed logins, like WHMCS (Settings → Login security, `gaiacp.login_security`):
 * `max_attempts` wrong passwords or two-factor codes on either panel within `ban_minutes` keep that address out of
 * the login, sign up and forgot password pages for `ban_minutes`. Addresses on the allow-list are never counted or
 * banned. The visitor's address is $request->ip(), so behind Cloudflare it is the visitor's (TrustProxies).
 * Filament's own limits (5 logins / 2 reset emails a minute per form) still apply on top.
 */
final class LoginSecurity
{
    public static function enabled(): bool
    {
        return (bool) config('gaiacp.login_security.enabled');
    }

    public static function maxAttempts(): int
    {
        return max(1, (int) config('gaiacp.login_security.max_attempts'));
    }

    public static function banMinutes(): int
    {
        return max(1, (int) config('gaiacp.login_security.ban_minutes'));
    }

    /** @return list<string> addresses and ranges (CIDR) that are never banned */
    public static function allowList(): array
    {
        $value = config('gaiacp.login_security.allowed_ips');

        return array_values(array_filter(array_map('trim', is_array($value) ? $value : preg_split('/[\s,]+/', (string) $value))));
    }

    public static function isAllowListed(string $ip): bool
    {
        $list = self::allowList();

        return $list !== [] && IpUtils::checkIp($ip, $list);
    }

    /** One spelling per address, so "2001:DB8::1" and "2001:db8:0::1" are the same ban. */
    public static function normalize(string $ip): string
    {
        $packed = @inet_pton(trim($ip));

        return $packed === false ? trim($ip) : (string) inet_ntop($packed);
    }

    /** The ban keeping this address out now, if any. */
    public static function banFor(?string $ip): ?IpBan
    {
        if ($ip === null || ! self::enabled() || self::isAllowListed($ip)) {
            return null;
        }

        return IpBan::query()->active()->where('ip', self::normalize($ip))->first();
    }

    /**
     * A wrong password or two-factor code from this address. Returns the ban when this one was the last allowed.
     *
     * @param  string  $panel  admin, client or api (a wrong REST API key)
     */
    public static function recordFailure(?string $ip, string $panel, ?string $email = null): ?IpBan
    {
        if ($ip === null || ! self::enabled() || self::isAllowListed($ip)) {
            return null;
        }

        $ip = self::normalize($ip);
        $key = 'gaiacp-login-failures:'.$ip;
        $attempts = RateLimiter::hit($key, self::banMinutes() * 60);

        if ($attempts < self::maxAttempts()) {
            return null;
        }

        RateLimiter::clear($key);

        $ban = self::ban($ip, null, now()->addMinutes(self::banMinutes()), $attempts);

        ActivityLog::record('ip.banned', "Banned {$ip} for ".self::banMinutes()." minutes after {$attempts} failed logins on the {$panel} login", properties: array_filter([
            'ip' => $ip,
            'panel' => $panel,
            'attempts' => $attempts,
            'email' => $email, // the address tried last
            'expires_at' => $ban->expires_at?->toIso8601String(),
        ]));

        return $ban;
    }

    /** Ban (or ban again) an address; null $until = until an admin lifts it. */
    public static function ban(string $ip, ?string $reason, ?CarbonInterface $until, ?int $attempts = null): IpBan
    {
        return IpBan::query()->updateOrCreate(['ip' => self::normalize($ip)], [
            'reason' => $reason,
            'attempts' => $attempts,
            'expires_at' => $until,
            'user_id' => $attempts === null ? auth()->id() : null,
        ]);
    }

    public static function lift(IpBan $ban): void
    {
        RateLimiter::clear('gaiacp-login-failures:'.$ban->ip);
        $ban->delete();

        ActivityLog::record('ip.ban_lifted', "Lifted the ban on {$ban->ip}", properties: ['ip' => $ban->ip]);
    }
}
