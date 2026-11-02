<?php

namespace App\Filament\Resources\Users;

use App\Models\ActivityLog;
use App\Models\Passkey;
use App\Models\User;
use App\Providers\AppServiceProvider;
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

    /** For an admin whose device was lost or whose login may have been taken over: every passkey of theirs stops working. */
    public static function removePasskeys(): Action
    {
        return Action::make('removePasskeys')
            ->label(fn (User $record) => __('Remove passkeys (:count)', ['count' => $record->passkeys()->count()]))
            ->icon(Heroicon::OutlinedFingerPrint)
            ->color('danger')
            ->visible(fn (User $record) => $record->passkeys()->exists())
            ->requiresConfirmation()
            ->modalDescription(fn (User $record) => self::passkeysDescription($record))
            ->action(fn (User $record) => self::deletePasskeys($record, 'an admin'));
    }

    /** What the confirmation lists: each passkey with when it was added and last used. */
    public static function passkeysDescription(User $user): string
    {
        $format = AppServiceProvider::dateFormat();
        $list = $user->passkeys()->latest()->get()
            ->map(fn (Passkey $passkey) => $passkey->name.' ('.__('added :date', ['date' => $passkey->created_at->format($format)])
                .($passkey->last_used_at ? ', '.__('last used :date', ['date' => $passkey->last_used_at->format($format)]) : '').')')
            ->implode('; ');

        return __('These stop working: :list. :email logs in with the password (and two-factor code) and can add new ones.', ['list' => $list, 'email' => $user->email]);
    }

    public static function deletePasskeys(User $user, string $by): void
    {
        $count = $user->passkeys()->count();
        $user->passkeys()->delete();
        ActivityLog::record('sign_in.passkeys_removed', "{$count} passkey(s) of {$user->email} removed by {$by}", client: $user->client);
        Notification::make()->success()->title(__('Passkeys removed for :email.', ['email' => $user->email]))->send();
    }

    /** Only for admins without history; the rest are deactivated instead. Never yourself. */
    public static function delete(): DeleteAction
    {
        return DeleteAction::make()
            ->hidden(fn (User $record) => $record->is(auth()->user()) || ! $record->canBeDeleted())
            ->after(fn (User $record) => ActivityLog::record('user.deleted', "Admin user {$record->email} deleted"));
    }
}
