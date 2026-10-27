<?php

namespace Plugins\EasyGr;

use App\Plugins\Plugin;
use App\Provisioning\DomainRegistrar;
use App\Provisioning\RegistrarModule;
use Filament\Forms\Components\TextInput;

/** easy.gr domain registrations, renewals, transfers and sync (EasyClient, EasyRegistrar). Registrar module "easygr". */
class EasyGrPlugin extends Plugin implements RegistrarModule
{
    public function id(): string
    {
        return 'easygr';
    }

    public function name(): string
    {
        return 'Easy.gr';
    }

    public function description(): string
    {
        return __('Registers, renews, transfers and syncs domains through an easy.gr reseller account.');
    }

    public function category(): string
    {
        return 'Registrars';
    }

    /** Off in a new install: only Greek sellers use it. */
    public function enabledByDefault(): bool
    {
        return false;
    }

    public function moduleLogs(): array
    {
        return ['easy' => 'easy.gr'];
    }

    public function key(): string
    {
        return 'easygr';
    }

    public function label(): string
    {
        return 'Easy.gr';
    }

    public function registrar(array $credentials): DomainRegistrar
    {
        return new EasyRegistrar(new EasyClient($credentials));
    }

    public function credentialFields(): array
    {
        return [
            TextInput::make('credentials.username')
                ->label('Username')
                ->autocomplete('off')
                ->required()
                ->maxLength(255),
            TextInput::make('credentials.password')
                ->label('Password')
                ->password()
                ->revealable()
                ->autocomplete('new-password')
                ->maxLength(255)
                ->required(fn (string $operation) => $operation === 'create')
                // Not loaded into the page; a blank field keeps the stored one.
                ->formatStateUsing(fn () => null)
                ->helperText(fn (string $operation) => $operation === 'edit' ? __('Leave empty to keep the current password. Stored encrypted.') : __('Stored encrypted.')),
        ];
    }
}
