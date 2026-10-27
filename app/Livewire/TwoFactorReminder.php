<?php

namespace App\Livewire;

use App\Models\User;
use Livewire\Component;

/**
 * The notice at the top of both panels asking a user without two-factor authentication to turn it on (on their
 * Profile page). Shown until they turn it on or close it; closing it is kept on the user, so it stays closed.
 */
class TwoFactorReminder extends Component
{
    public bool $dismissed = false;

    public static function shows(): bool
    {
        $user = filament()->auth()->user();

        return $user instanceof User
            && filament()->hasMultiFactorAuthentication()
            && filament()->hasProfile()
            && ! $user->hasTwoFactor()
            && $user->two_factor_reminder_dismissed_at === null
            && ! request()->routeIs('filament.*.auth.profile');
    }

    public function dismiss(): void
    {
        $user = filament()->auth()->user();

        if ($user instanceof User) {
            $user->forceFill(['two_factor_reminder_dismissed_at' => now()])->save();
        }

        $this->dismissed = true;
    }

    public function render()
    {
        return view('livewire.two-factor-reminder', [
            'profileUrl' => filament()->getProfileUrl(),
            'client' => filament()->getId() === 'client',
        ]);
    }
}
