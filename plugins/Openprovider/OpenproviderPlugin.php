<?php

namespace Plugins\Openprovider;

use App\Plugins\Plugin;
use App\Provisioning\DomainRegistrar;
use App\Provisioning\RegistrarModule;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;

/** Openprovider domain registrations, renewals, transfers and sync (OpenproviderClient, OpenproviderRegistrar). Registrar module "openprovider". */
class OpenproviderPlugin extends Plugin implements RegistrarModule
{
    public function id(): string
    {
        return 'openprovider';
    }

    public function name(): string
    {
        return 'Openprovider';
    }

    public function description(): string
    {
        return __('Registers, renews, transfers and syncs domains through an Openprovider reseller account.');
    }

    public function category(): string
    {
        return 'Registrars';
    }

    public function moduleLogs(): array
    {
        return ['openprovider' => 'Openprovider'];
    }

    public function key(): string
    {
        return 'openprovider';
    }

    public function label(): string
    {
        return 'Openprovider';
    }

    public function registrar(array $credentials): DomainRegistrar
    {
        return new OpenproviderRegistrar(new OpenproviderClient($credentials));
    }

    public function credentialFields(): array
    {
        return [
            TextInput::make('credentials.username')
                ->label('Openprovider username')
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
                ->helperText(fn (string $operation) => $operation === 'edit'
                    ? __('Leave empty to keep the current password. Stored encrypted. Openprovider answers only the IP addresses you allow under the API settings of your account.')
                    : __('Stored encrypted. Openprovider answers only the IP addresses you allow under the API settings of your account.')),
            Toggle::make('credentials.sandbox')
                ->label('Sandbox')
                ->helperText(__('Sends every call to Openprovider\'s sandbox (api.sandbox.openprovider.nl), with a sandbox account\'s login.')),
        ];
    }
}
