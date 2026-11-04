<?php

namespace App\Enums;

use App\Models\Client;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Database\Eloquent\Builder;

/**
 * Whether a client can log in to the client area (the "Client area" menu on the client page turns it off and on):
 * shown in the client list (column + filter) and on the client page's Contact card.
 */
enum ClientAreaLogin: string implements HasColor, HasLabel
{
    /** A client login that is turned on. */
    case On = 'on';
    /** A client login turned off from the Client area menu, or made by Log in as client before an invitation. */
    case Off = 'off';
    /** Never invited, no password set. */
    case None = 'none';
    /** An admin user linked to the client: one login for /admin and the client area. */
    case Admin = 'admin';

    public static function for(Client $client): self
    {
        $user = $client->user;

        return match (true) {
            $user === null => self::None,
            $user->role !== UserRole::Client => self::Admin,
            $user->active => self::On,
            default => self::Off,
        };
    }

    /** @param  Builder<Client>  $query */
    public function apply(Builder $query): void
    {
        match ($this) {
            self::None => $query->whereDoesntHave('user'),
            self::Admin => $query->whereHas('user', fn (Builder $user) => $user->where('role', '!=', UserRole::Client)),
            self::On, self::Off => $query->whereHas('user', fn (Builder $user) => $user
                ->where('role', UserRole::Client)
                ->where('active', $this === self::On)),
        };
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::On => __('Login on'),
            self::Off => __('Login turned off'),
            self::None => __('No login'),
            self::Admin => __('Admin user'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::On => 'success',
            self::Off => 'danger',
            self::None => 'gray',
            self::Admin => 'info',
        };
    }
}
