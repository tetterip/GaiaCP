<?php

namespace App\Listeners;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\PasswordResetLinkSent;

/** "Forgot password?" in the activity log: the link sent, then the password changed with it. Discovered automatically. */
class LogPasswordReset
{
    public function handle(PasswordResetLinkSent|PasswordReset $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        $event instanceof PasswordReset
            ? ActivityLog::record('user.password_reset', "Password of admin user {$event->user->email} reset with the emailed link")
            : ActivityLog::record('user.password_reset_requested', "Password reset link emailed to admin user {$event->user->email}");
    }
}
