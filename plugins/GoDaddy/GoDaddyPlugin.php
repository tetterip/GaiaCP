<?php

namespace Plugins\GoDaddy;

use App\Plugins\Plugin;
use App\Provisioning\DomainRegistrar;
use App\Provisioning\RegistrarModule;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;

/** GoDaddy domain registrations, renewals, transfers and sync (GoDaddyClient, GoDaddyRegistrar). Registrar module "godaddy". */
class GoDaddyPlugin extends Plugin implements RegistrarModule
{
    public function id(): string
    {
        return 'godaddy';
    }

    public function name(): string
    {
        return 'GoDaddy';
    }

    public function description(): string
    {
        return __('Registers, renews, transfers and syncs domains through a GoDaddy account with an API key.');
    }

    public function category(): string
    {
        return 'Registrars';
    }

    public function moduleLogs(): array
    {
        return ['godaddy' => 'GoDaddy'];
    }

    public function key(): string
    {
        return 'godaddy';
    }

    public function label(): string
    {
        return 'GoDaddy';
    }

    public function registrar(array $credentials): DomainRegistrar
    {
        return new GoDaddyRegistrar(new GoDaddyClient($credentials));
    }

    public function credentialFields(): array
    {
        $secret = fn (string $name) => TextInput::make("credentials.{$name}")
            ->password()
            ->revealable()
            ->autocomplete('new-password')
            ->maxLength(255)
            ->required(fn (string $operation) => $operation === 'create')
            // Not loaded into the page; a blank field keeps the stored one.
            ->formatStateUsing(fn () => null)
            ->helperText(fn (string $operation) => $operation === 'edit'
                ? __('Leave empty to keep the current one. Stored encrypted.')
                : __('Stored encrypted.'));

        return [
            $secret('api_key')
                ->label('API key')
                ->helperText(fn (string $operation) => $operation === 'edit'
                    ? __('Leave empty to keep the current key. Stored encrypted. Make a production key at developer.godaddy.com → API Keys (an OTE key for the test environment).')
                    : __('Stored encrypted. Make a production key at developer.godaddy.com → API Keys (an OTE key for the test environment).')),
            $secret('api_secret')
                ->label('API secret'),
            TextInput::make('credentials.shopper_id')
                ->label('Shopper ID')
                ->autocomplete('off')
                ->maxLength(64)
                ->helperText(__('Only for a reseller sub-account: the customer number whose domains these are. Leave empty for your own account.')),
            Toggle::make('credentials.test_mode')
                ->label('Test mode')
                ->helperText(__('Sends every call to GoDaddy\'s test environment (api.ote-godaddy.com), with an OTE key.')),
        ];
    }
}
