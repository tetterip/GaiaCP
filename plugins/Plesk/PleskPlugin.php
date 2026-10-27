<?php

namespace Plugins\Plesk;

use App\Models\Server;
use App\Plugins\Plugin;
use App\Provisioning\HostingProvisioner;
use App\Provisioning\ServerModule;
use Filament\Forms\Components\TextInput;

/** Plesk subscriptions and service plans over the XML API (PleskClient, PleskProvisioner). Servers of type "plesk". */
class PleskPlugin extends Plugin implements ServerModule
{
    public function id(): string
    {
        return 'plesk';
    }

    public function name(): string
    {
        return 'Plesk';
    }

    public function description(): string
    {
        return __('Creates, suspends and removes hosting subscriptions on Plesk servers.');
    }

    public function category(): string
    {
        return 'Servers';
    }

    public function moduleLogs(): array
    {
        return ['plesk' => 'Plesk'];
    }

    public function key(): string
    {
        return 'plesk';
    }

    public function label(): string
    {
        return 'Plesk';
    }

    public function defaultPort(): int
    {
        return 8443;
    }

    public function clientPort(): int
    {
        return 8443;
    }

    public function provisioner(Server $server): HostingProvisioner
    {
        return new PleskProvisioner(new PleskClient($server), $server);
    }

    /** Empty = authenticate with a secret key. */
    public function usernameRequired(): bool
    {
        return false;
    }

    public function usernameHelp(): string
    {
        return __('Plesk admin login. Leave empty to authenticate with a secret key instead.');
    }

    public function tokenHelp(): string
    {
        return __('Plesk: the admin password, or a secret key (plesk bin secret_key -c) if the login is empty. Stored encrypted.');
    }

    public function serverFields(): array
    {
        return [
            TextInput::make('shared_ip')
                ->label('IP for new subscriptions')
                ->ip()
                ->helperText(__('Leave empty to use the server\'s first shared IPv4 address.')),
        ];
    }
}
