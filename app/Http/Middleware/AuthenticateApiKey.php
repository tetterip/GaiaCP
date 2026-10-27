<?php

namespace App\Http\Middleware;

use App\Install\Installer;
use App\Models\ApiKey;
use App\Support\LoginSecurity;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every REST API call: answered only with an active key (Authorization: Bearer gcp_…, or X-API-Key) from one of its
 * allowed IPs, never to a banned address; wrong keys count as failed logins.
 * Notes when and from where the key was last used, and makes it ApiKey::current() for the call, so the activity log
 * says which key did what. `api.key:clients.read` also needs that permission.
 */
class AuthenticateApiKey
{
    /** Wrong keys one address may send in a minute before it is answered 429 (Login security may ban it sooner). */
    public const FAILURES_PER_MINUTE = 10;

    public function __construct(private Installer $installer) {}

    public function handle(Request $request, Closure $next, ?string $permission = null): Response
    {
        if ($permission !== null) {
            return $this->checkPermission($request, $next, $permission);
        }

        if ($this->installer->webInstallerOpen()) {
            return response()->json(['message' => 'GaiaCP is not installed yet.'], 503);
        }

        // An address banned under Settings → Login security, or that sent too many wrong keys lately
        $failures = 'gaiacp-api-failures:'.$request->ip();
        if (LoginSecurity::banFor($request->ip()) !== null) {
            return response()->json(['message' => 'This address is banned.'], 403);
        }
        if (RateLimiter::tooManyAttempts($failures, self::FAILURES_PER_MINUTE)) {
            return response()->json(['message' => 'Too many wrong API keys from this address. Try again later.'], 429, ['Retry-After' => RateLimiter::availableIn($failures)]);
        }

        // X-API-Key for hosts whose web server drops the Authorization header
        $token = $request->bearerToken() ?? $request->header('X-API-Key');
        $key = ApiKey::findByToken($token);

        if ($key === null) {
            // A wrong key counts like a wrong password (bans after Settings → Login security's attempts)
            if (filled($token)) {
                RateLimiter::hit($failures, 60);
                LoginSecurity::recordFailure($request->ip(), 'api');
            }

            return response()->json(['message' => 'Missing, wrong or revoked API key.'], 401, ['WWW-Authenticate' => 'Bearer']);
        }

        if (! $key->allowsIp($request->ip())) {
            return response()->json(['message' => "This API key is not allowed from {$request->ip()}."], 403);
        }

        // At most once a minute, so busy keys don't write on every call
        if ($key->last_used_at === null || $key->last_used_at->lt(now()->subMinute()) || $key->last_used_ip !== $request->ip()) {
            $key->forceFill(['last_used_at' => now(), 'last_used_ip' => $request->ip()])->saveQuietly();
        }

        ApiKey::setCurrent($key);

        try {
            return $next($request);
        } finally {
            ApiKey::setCurrent(null);
        }
    }

    private function checkPermission(Request $request, Closure $next, string $permission): Response
    {
        [$area, $action] = explode('.', $permission, 2);

        if (! ApiKey::current()?->can($area, $action)) {
            return response()->json(['message' => "This API key may not {$action} {$area} ({$permission})."], 403);
        }

        return $next($request);
    }
}
