<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserActions;
use App\Filament\Resources\Users\UserResource;
use App\Models\ActivityLog;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Resources\Pages\EditRecord;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            UserActions::disableTwoFactor(),
            UserActions::removePasskeys(),
            UserActions::delete(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        if ($data['super_admin'] ?? false) {
            $data['admin_areas'] = null;
        }

        return $data;
    }

    protected function afterSave(): void
    {
        /** @var User $user */
        $user = $this->getRecord();
        $changed = array_keys($user->getChanges());

        if (in_array('active', $changed, true)) {
            ActivityLog::record($user->active ? 'user.activated' : 'user.deactivated', 'Admin user '.$user->email.($user->active ? ' activated' : ' deactivated'));
        }

        if (in_array('password', $changed, true)) {
            if ($user->is(auth()->user())) {
                // Like the profile page: keep this session, log out the others.
                session()->put(['password_hash_'.Filament::getAuthGuard() => $user->getAuthPassword()]);
            }

            ActivityLog::record('user.password_changed', "Password of admin user {$user->email} changed by an admin");
        }

        if (array_intersect($changed, ['super_admin', 'admin_areas'])) {
            ActivityLog::record('user.access_changed', "Access of admin user {$user->email} changed", properties: [
                'super_admin' => $user->super_admin,
                'areas' => $user->super_admin ? null : ($user->admin_areas ?? []),
            ]);
        }

        if (array_diff($changed, ['active', 'password', 'super_admin', 'admin_areas', 'updated_at', 'remember_token'])) {
            ActivityLog::record('user.updated', "Admin user {$user->email} updated", properties: ['changed' => array_values(array_diff($changed, ['password', 'super_admin', 'admin_areas', 'updated_at', 'remember_token']))]);
        }
    }
}
