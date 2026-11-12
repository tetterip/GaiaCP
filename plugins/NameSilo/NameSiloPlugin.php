<?php

namespace Plugins\NameSilo;

use App\Plugins\Plugin;
use App\Provisioning\DomainRegistrar;
use App\Provisioning\RegistrarModule;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;

/** NameSilo domain registrations, renewals, transfers and sync (NameSiloClient, NameSiloRegistrar). Registrar module "namesilo". */
class NameSiloPlugin extends Plugin implements RegistrarModule
{
    public function id(): string
    {
        return 'namesilo';
    }

    public function name(): string
    {
        return 'NameSilo';
    }

    public function description(): string
    {
        return __('Registers, renews, transfers and syncs domains through a NameSilo account with an API key.');
    }

    public function category(): string
    {
        return 'Registrars';
    }

    public function moduleLogs(): array
    {
        return ['namesilo' => 'NameSilo'];
    }

    public function key(): string
    {
        return 'namesilo';
    }

    public function label(): string
    {
        return 'NameSilo';
    }

    public function registrar(array $credentials): DomainRegistrar
    {
        return new NameSiloRegistrar(new NameSiloClient($credentials));
    }

    public function credentialFields(): array
    {
        return [
            TextInput::make('credentials.api_key')
                ->label('API key')
                ->password()
                ->revealable()
                ->autocomplete('new-password')
                ->maxLength(255)
                ->required(fn (string $operation) => $operation === 'create')
                // Not loaded into the page; a blank field keeps the stored one.
                ->formatStateUsing(fn () => null)
                ->helperText(fn (string $operation) => $operation === 'edit'
                    ? __('Leave empty to keep the current key. Stored encrypted. Make it under Account → API Manager at NameSilo, and limit it to this server\'s IP address there.')
                    : __('Stored encrypted. Make it under Account → API Manager at NameSilo, and limit it to this server\'s IP address there.')),
            Toggle::make('credentials.sandbox')
                ->label('Sandbox')
                ->helperText(__('Sends every call to NameSilo\'s sandbox (sandbox.namesilo.com), with a sandbox account\'s API key.')),
        ];
    }
}
