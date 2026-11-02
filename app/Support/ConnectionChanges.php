<?php

namespace App\Support;

use App\Enums\UserRole;
use App\Models\ActivityLog;
use App\Models\Registrar;
use App\Models\Server;
use App\Models\User;
use App\Notifications\ConnectionDetailsChanged;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A server's or registrar's login (address, user, API token or password) added or changed by an admin: logged, and
 * emailed to the super admins with Receive system emails on, so a change made from a stolen admin session does not go
 * unnoticed. Changes made without a signed-in admin (installer, WHMCS import, seeders, tests) are left alone.
 */
final class ConnectionChanges
{
    /** Server fields that say where and as whom GaiaCP logs in. */
    public const SERVER_FIELDS = ['hostname', 'port', 'username', 'api_token', 'type', 'verify_tls'];

    public static function serverSaved(Server $server): void
    {
        if ($server->wasRecentlyCreated || $server->wasChanged(self::SERVER_FIELDS)) {
            self::report('server', $server->name, $server->wasRecentlyCreated, $server->wasChanged('api_token'));
        }
    }

    public static function registrarSaved(Registrar $registrar): void
    {
        if ($registrar->wasRecentlyCreated || $registrar->wasChanged(['credentials', 'module', 'verify_tls'])) {
            self::report('registrar', $registrar->name, $registrar->wasRecentlyCreated, $registrar->wasChanged('credentials'));
        }
    }

    private static function report(string $kind, string $name, bool $added, bool $secretChanged): void
    {
        $by = auth()->user();

        if (! $by instanceof User || $by->role !== UserRole::Admin) {
            return;
        }

        $what = $added ? 'added' : ($secretChanged ? 'login changed' : 'connection details changed');
        ActivityLog::record("{$kind}.login_changed", ucfirst($kind)." {$name}: {$what} by {$by->email}", properties: ['ip' => request()->ip()]);

        $recipients = User::query()->admins()->where('active', true)->where('super_admin', true)->where('system_emails', true)->get();

        foreach ($recipients as $admin) {
            try {
                $admin->notify(new ConnectionDetailsChanged($kind, $name, $added, $by, (string) request()->ip()));
            } catch (Throwable $e) {
                Log::error('Connection change email failed', ['user_id' => $admin->id, 'error' => $e->getMessage()]);
                ActivityLog::record('email.failed', "Connection change email to {$admin->email} failed: {$e->getMessage()}");
            }
        }
    }
}
