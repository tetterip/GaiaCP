<?php

namespace Plugins\Enom;

use App\Plugins\Plugin;
use App\Provisioning\DomainRegistrar;
use App\Provisioning\RegistrarModule;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;

/** Enom domain registrations, renewals, transfers and sync (EnomClient, EnomRegistrar). Registrar module "enom". */
class EnomPlugin extends Plugin implements RegistrarModule
{
    public function id(): string
    {
        return 'enom';
    }

    public function name(): string
    {
        return 'Enom';
    }

    public function description(): string
    {
        return __('Registers, renews, transfers and syncs domains through an Enom reseller account.');
    }

    public function category(): string
    {
        return 'Registrars';
    }

    public function moduleLogs(): array
    {
        return ['enom' => 'Enom'];
    }

    public function key(): string
    {
        return 'enom';
    }

    public function label(): string
    {
        return 'Enom';
    }

    public function registrar(array $credentials): DomainRegistrar
    {
        return new EnomRegistrar(new EnomClient($credentials));
    }

    public function credentialFields(): array
    {
        return [
            TextInput::make('credentials.uid')
                ->label('Login ID')
                ->autocomplete('off')
                ->required()
                ->maxLength(255),
            TextInput::make('credentials.password')
                ->label('API token')
                ->password()
                ->revealable()
                ->autocomplete('new-password')
                ->maxLength(255)
                ->required(fn (string $operation) => $operation === 'create')
                // Not loaded into the page; a blank field keeps the stored one.
                ->formatStateUsing(fn () => null)
                ->helperText(fn (string $operation) => $operation === 'edit'
                    ? __('Leave empty to keep the current token. Stored encrypted. Enom answers only the IP addresses you allow under API settings in its reseller panel.')
                    : __('Stored encrypted. Enom answers only the IP addresses you allow under API settings in its reseller panel.')),
            Toggle::make('credentials.test_mode')
                ->label('Test mode')
                ->helperText(__('Sends every call to Enom\'s reseller test environment (resellertest.enom.com), with that account\'s login.')),
        ];
    }
}
