<?php

namespace Plugins\Namecheap;

use App\Plugins\Plugin;
use App\Provisioning\DomainRegistrar;
use App\Provisioning\RegistrarModule;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;

/** Namecheap domain registrations, renewals, transfers and sync (NamecheapClient, NamecheapRegistrar). Registrar module "namecheap". */
class NamecheapPlugin extends Plugin implements RegistrarModule
{
    public function id(): string
    {
        return 'namecheap';
    }

    public function name(): string
    {
        return 'Namecheap';
    }

    public function description(): string
    {
        return __('Registers, renews, transfers and syncs domains through a Namecheap account with API access.');
    }

    public function category(): string
    {
        return 'Registrars';
    }

    public function moduleLogs(): array
    {
        return ['namecheap' => 'Namecheap'];
    }

    public function key(): string
    {
        return 'namecheap';
    }

    public function label(): string
    {
        return 'Namecheap';
    }

    public function registrar(array $credentials): DomainRegistrar
    {
        return new NamecheapRegistrar(new NamecheapClient($credentials));
    }

    public function credentialFields(): array
    {
        return [
            TextInput::make('credentials.username')
                ->label('Namecheap username')
                ->autocomplete('off')
                ->required()
                ->maxLength(255),
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
                    ? __('Leave empty to keep the current key. Stored encrypted. Turn on API access under Profile → Tools → API Access in your Namecheap account.')
                    : __('Stored encrypted. Turn on API access under Profile → Tools → API Access in your Namecheap account.')),
            TextInput::make('credentials.client_ip')
                ->label('This server\'s IP address')
                ->required()
                ->ip()
                ->helperText(__('The public IPv4 address GaiaCP calls Namecheap from. Add it to the whitelisted IPs on the same API Access page.')),
            Toggle::make('credentials.sandbox')
                ->label('Sandbox')
                ->helperText(__('Sends every call to Namecheap\'s sandbox (api.sandbox.namecheap.com), with a sandbox account\'s login.')),
        ];
    }
}
