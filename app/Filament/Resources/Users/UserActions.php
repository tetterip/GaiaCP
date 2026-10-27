<?php

namespace App\Filament\Resources\Users;

use App\Models\ActivityLog;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/** Actions on an admin user, shared by the list and the edit page. */
class UserActions
{
    /** For an admin who lost their authenticator app and recovery codes (your own is turned off on your profile). */
    public static function disableTwoFactor(): Action
    {
        return Action::make('disableTwoFactor')
            ->label('Turn off two-factor')
            ->icon(Heroicon::OutlinedShieldExclamation)
            ->color('warning')
            ->visible(fn (User $record) => $record->hasTwoFactor() && ! $record->is(auth()->user()))
            ->requiresConfirmation()
            ->modalDescription(fn (User $record) => __(':name will log in with the password only, until they turn two-factor authentication on again from their profile.', ['name' => $record->name]))
            ->action(function (User $record): void {
                $record->forceFill([
                    'app_authentication_secret' => null,
                    'app_authentication_recovery_codes' => null,
                ])->save();

                ActivityLog::record('user.2fa_disabled', "Two-factor authentication turned off for {$record->email} by an admin");

                Notification::make()->success()->title(__('Two-factor authentication is off for :email.', ['email' => $record->email]))->send();
            });
    }

    /** Only for admins without history; the rest are deactivated instead. Never yourself. */
    public static function delete(): DeleteAction
    {
        return DeleteAction::make()
            ->hidden(fn (User $record) => $record->is(auth()->user()) || ! $record->canBeDeleted())
            ->after(fn (User $record) => ActivityLog::record('user.deleted', "Admin user {$record->email} deleted"));
    }
}
