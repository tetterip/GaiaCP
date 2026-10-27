<?php

namespace App\Filament\Resources\Users\Pages;

use App\Enums\UserRole;
use App\Filament\Resources\Users\UserResource;
use App\Models\ActivityLog;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return [...$data, 'role' => UserRole::Admin, 'admin_areas' => ($data['super_admin'] ?? false) ? null : ($data['admin_areas'] ?? [])];
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
