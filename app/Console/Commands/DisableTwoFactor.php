<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('gaiacp:disable-2fa {email : The admin user\'s email}')]
#[Description('Turn off two-factor authentication for an admin who lost their authenticator app and recovery codes')]
class DisableTwoFactor extends Command
{
    public function handle(): int
    {
        $user = User::where('email', $this->argument('email'))->first();

        if (! $user) {
            $this->error('No user with that email.');

            return self::FAILURE;
        }

        if (blank($user->getAppAuthenticationSecret())) {
            $this->info("Two-factor authentication is already off for {$user->email}.");

            return self::SUCCESS;
        }

        $user->forceFill([
            'app_authentication_secret' => null,
            'app_authentication_recovery_codes' => null,
        ])->save();

        ActivityLog::record('user.2fa_disabled', "Two-factor authentication turned off for {$user->email} from the command line");

        $this->info("Two-factor authentication is off for {$user->email}. They can turn it on again from their profile.");

        return self::SUCCESS;
    }
}
