<?php

namespace App\Listeners;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;

/**
 * A password reset through Forgot password? (either panel) removes the login's passkeys: whoever lost control of their
 * login gets it back whole, without a passkey someone else added. Discovered automatically (app/Listeners).
 */
class RemovePasskeysOnPasswordReset
{
    public function handle(PasswordReset $event): void
    {
        $user = $event->user;

        if (! $user instanceof User || ($count = $user->passkeys()->count()) === 0) {
            return;
        }

        $user->passkeys()->delete();
        ActivityLog::record('sign_in.passkeys_removed', "{$count} passkey(s) of {$user->email} removed by a password reset", client: $user->client);
    }
}
