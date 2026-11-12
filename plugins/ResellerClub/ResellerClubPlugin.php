<?php

namespace Plugins\ResellerClub;

use App\Plugins\Plugin;
use App\Provisioning\DomainRegistrar;
use App\Provisioning\RegistrarModule;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;

/** ResellerClub (LogicBoxes) domain registrations, renewals, transfers and sync (ResellerClubClient, ResellerClubRegistrar). Registrar module "resellerclub". */
class ResellerClubPlugin extends Plugin implements RegistrarModule
{
    public function id(): string
    {
        return 'resellerclub';
    }

    public function name(): string
    {
        return 'ResellerClub';
    }

    public function description(): string
    {
        return __('Registers, renews, transfers and syncs domains through a ResellerClub reseller account.');
    }

    public function category(): string
    {
        return 'Registrars';
    }

    public function moduleLogs(): array
    {
        return ['resellerclub' => 'ResellerClub'];
    }

    public function key(): string
    {
        return 'resellerclub';
    }

    public function label(): string
    {
        return 'ResellerClub';
    }

    public function registrar(array $credentials): DomainRegistrar
    {
        return new ResellerClubRegistrar(new ResellerClubClient($credentials));
    }

    public function credentialFields(): array
    {
        return [
            TextInput::make('credentials.reseller_id')
                ->label('Reseller ID')
                ->autocomplete('off')
                ->required()
                ->maxLength(32),
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
                    ? __('Leave empty to keep the current key. Stored encrypted. Find it under Settings → API in the ResellerClub panel, and allow this server\'s IP address there.')
                    : __('Stored encrypted. Find it under Settings → API in the ResellerClub panel, and allow this server\'s IP address there.')),
            Toggle::make('credentials.test_mode')
                ->label('Test mode')
                ->helperText(__('Sends every call to ResellerClub\'s test environment (test.httpapi.com), with a demo reseller account.')),
        ];
    }
}
