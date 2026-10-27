<?php

namespace App\Filament\Client\Pages\Auth;

use Filament\Auth\Pages\EmailVerification\EmailVerificationPrompt as BaseEmailVerificationPrompt;
use Illuminate\Contracts\Auth\MustVerifyEmail;

/** "Confirm your email address", for clients who signed up: "Resend" sends the Email Address Verification email again, at once. */
class EmailVerificationPrompt extends BaseEmailVerificationPrompt
{
    protected function sendEmailVerificationNotification(MustVerifyEmail $user): void
    {
        if (! $user->hasVerifiedEmail()) {
            $user->sendEmailVerificationNotification();
        }
    }
}
