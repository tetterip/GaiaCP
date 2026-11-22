<?php

namespace App\Filament\Resources\Users\Pages;

use App\Enums\UserRole;
use App\Filament\Resources\Users\UserResource;
use App\Models\ActivityLog;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    protected static bool $canCreateAnother = false;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return [...$data, 'role' => UserRole::Admin, 'admin_areas' => ($data['super_admin'] ?? false) ? null : ($data['admin_areas'] ?? [])];
    }

    /** @param  array<string, mixed>  $data */
    protected function handleRecordCreation(array $data): Model
    {
        // super_admin and admin_areas are not mass assignable (User): this form is where a super admin sets them
        return User::query()->forceCreate($data);
    }

    protected function afterCreate(): void
    {
        /** @var User $user */
        $user = $this->getRecord();

        ActivityLog::record('user.created', "Admin user {$user->email} added", properties: [
            'super_admin' => $user->super_admin,
            'areas' => $user->super_admin ? null : ($user->admin_areas ?? []),
        ]);
    }
}
