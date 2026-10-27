<?php

namespace App\ClientSessions;

use App\Actions\ClientAreaAccess;
use App\Models\ActivityLog;
use App\Models\ClientLogin;
use App\Models\ClientSession;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * The client area's signed-in browsers and sign-in history. Each signed-in browser keeps a random token in its session
 * and a `client_sessions` row with its hash (TrackClientSession, on every client area request); the first request
 * after a sign-in makes the row and a `client_logins` line. Deleting a row ("Log out" on the Sign-ins page, or the
 * admin's "Log out everywhere") signs that browser out on its next request. An admin looking through "Log in as
 * client" leaves no trace here.
 */
class ClientSessions
{
    /** Session key: the token of this browser's row. */
    public const KEY = 'gaiacp.client_session';

    /** Sign-in history older than this is deleted (gaiacp:prune-logs). */
    public const KEEP_DAYS = 180;

    /** last_active_at is written at most this often per browser. */
    private const TOUCH_SECONDS = 60;

    /** Called on every client area request: makes, refreshes or ends this browser's row. */
    public function track(Request $request): void
    {
        $user = Auth::guard('client')->user();

        if (! $user instanceof User || ClientAreaAccess::viewing()) {
            return;
        }

        $token = $request->session()->get(self::KEY);
        $row = is_string($token) ? ClientSession::query()->where('token_hash', hash('sha256', $token))->first() : null;

        if ($row === null && is_string($token)) {
            // Logged out from another browser or by an admin
            Auth::guard('client')->logout();
            $request->session()->forget(self::KEY);

            return;
        }

        if ($row === null) {
            $this->start($user, $request);

            return;
        }

        if ($row->user_id !== $user->id) {
            $row->delete();
            $this->start($user, $request);

            return;
        }

        if ($row->last_active_at->diffInSeconds(now()) >= self::TOUCH_SECONDS || $row->ip_address !== $request->ip()) {
            $row->update(['last_active_at' => now(), 'ip_address' => $request->ip(), 'user_agent' => $this->agent($request)]);
        }
    }

    /** A failed client area sign-in with the email of a login (the Failed event). */
    public function failed(User $user, Request $request): void
    {
        ClientLogin::query()->create([
            'user_id' => $user->id,
            'client_id' => $user->client_id,
            'succeeded' => false,
            'ip_address' => $request->ip(),
            'user_agent' => $this->agent($request),
            'created_at' => now(),
        ]);
    }

    /** On sign out: this browser's row goes. */
    public function ended(Request $request): void
    {
        if ($request->hasSession() && is_string($token = $request->session()->get(self::KEY))) {
            ClientSession::query()->where('token_hash', hash('sha256', $token))->delete();
            $request->session()->forget(self::KEY);
        }
    }

    /** Signs out every browser of this login but the current one. @return int how many */
    public function logOutOthers(User $user): int
    {
        $count = ClientSession::query()->where('user_id', $user->id)->where('token_hash', '!=', (string) self::currentHash())->delete();

        if ($count > 0 && $user->client) {
            ActivityLog::record('client_area.sessions_ended', "Client signed out {$count} other device(s)", client: $user->client);
        }

        return $count;
    }

    /** Signs out every browser of this login (an admin's "Log out everywhere"). @return int how many */
    public function logOutEverywhere(User $user): int
    {
        $count = ClientSession::query()->where('user_id', $user->id)->delete();

        if ($user->client) {
            ActivityLog::record('client_area.sessions_ended', "Client area signed out on {$count} device(s) by an admin", client: $user->client);
        }

        return $count;
    }

    /** Rows of browsers not seen for longer than a session lasts, and old history. */
    public function prune(): int
    {
        return ClientSession::query()->where('last_active_at', '<', now()->subMinutes(max(1, (int) config('session.lifetime'))))->delete()
            + ClientLogin::query()->where('created_at', '<', now()->subDays(self::KEEP_DAYS))->delete();
    }

    /** The hash of this browser's token, null when it has none. */
    public static function currentHash(): ?string
    {
        $token = session()->get(self::KEY);

        return is_string($token) ? hash('sha256', $token) : null;
    }

    /** "Chrome on Windows" from a user agent; "Unknown device" when it says nothing known. */
    public static function device(?string $agent): string
    {
        $agent = (string) $agent;

        $browser = match (true) {
            str_contains($agent, 'Edg/') => 'Edge',
            str_contains($agent, 'OPR/') || str_contains($agent, 'Opera') => 'Opera',
            str_contains($agent, 'Firefox/') || str_contains($agent, 'FxiOS') => 'Firefox',
            str_contains($agent, 'SamsungBrowser') => 'Samsung Internet',
            str_contains($agent, 'Chrome/') || str_contains($agent, 'CriOS') => 'Chrome',
            str_contains($agent, 'Safari/') => 'Safari',
            default => null,
        };

        $system = match (true) {
            str_contains($agent, 'iPhone') => 'iPhone',
            str_contains($agent, 'iPad') => 'iPad',
            str_contains($agent, 'Android') => 'Android',
            str_contains($agent, 'Windows') => 'Windows',
            str_contains($agent, 'Mac OS X') || str_contains($agent, 'Macintosh') => 'macOS',
            str_contains($agent, 'CrOS') => 'ChromeOS',
            str_contains($agent, 'Linux') => 'Linux',
            default => null,
        };

        return match (true) {
            $browser !== null && $system !== null => __(':browser on :system', ['browser' => $browser, 'system' => $system]),
            default => $browser ?? $system ?? __('Unknown device'),
        };
    }

    private function start(User $user, Request $request): void
    {
        $token = Str::random(40);
        $request->session()->put(self::KEY, $token);

        ClientSession::query()->create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $token),
            'ip_address' => $request->ip(),
            'user_agent' => $this->agent($request),
            'last_active_at' => now(),
        ]);

        ClientLogin::query()->create([
            'user_id' => $user->id,
            'client_id' => $user->client_id,
            'succeeded' => true,
            'ip_address' => $request->ip(),
            'user_agent' => $this->agent($request),
            'created_at' => now(),
        ]);
    }

    private function agent(Request $request): ?string
    {
        $agent = $request->userAgent();

        return filled($agent) ? Str::limit($agent, 497) : null;
    }
}
