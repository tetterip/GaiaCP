<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * What a user account is for. Admins open the panel at /admin; clients (`users.client_id`) open only the client
 * area (App\Providers\Filament\ClientPanelProvider).
 */
enum UserRole: string implements HasLabel
{
    case Admin = 'admin';
    case Client = 'client';

    public function getLabel(): string
    {
        return match ($this) {
            self::Admin => __('Admin'),
            self::Client => __('Client'),
        };
    }

    public function canAccessAdminPanel(): bool
    {
        return $this === self::Admin;
    }
}
