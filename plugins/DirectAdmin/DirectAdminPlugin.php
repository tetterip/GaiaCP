<?php

namespace Plugins\DirectAdmin;

use App\Models\Server;
use App\Plugins\Plugin;
use App\Provisioning\HostingProvisioner;
use App\Provisioning\LimitsUsernameLength;
use App\Provisioning\ServerModule;
use Filament\Forms\Components\TextInput;

/** DirectAdmin users and packages over its legacy API (DirectAdminClient, DirectAdminProvisioner). Servers of type "directadmin". */
class DirectAdminPlugin extends Plugin implements LimitsUsernameLength, ServerModule
{
    public function id(): string
    {
        return 'directadmin';
    }

    public function name(): string
    {
        return 'DirectAdmin';
    }

    public function description(): string
    {
        return __('Creates, suspends and removes hosting accounts on DirectAdmin servers.');
    }

    public function category(): string
    {
        return 'Servers';
    }

    public function moduleLogs(): array
    {
        return ['directadmin' => 'DirectAdmin'];
    }

    public function key(): string
    {
        return 'directadmin';
    }

    public function label(): string
    {
        return 'DirectAdmin';
    }

    public function defaultPort(): int
    {
        return 2222;
    }

    public function clientPort(): int
    {
        return 2222;
    }

    public function provisioner(Server $server): HostingProvisioner
    {
        return new DirectAdminProvisioner(new DirectAdminClient($server), $server);
    }

    public function usernameRequired(): bool
    {
        return true;
    }

    public function usernameHelp(): string
    {
        return __('The admin or a reseller (e.g. admin). New accounts are created under it, on its packages.');
    }

    public function tokenHelp(): string
    {
        return __('DirectAdmin: a login key of that user (Login Keys, allowing the CMD_API commands), or its password. Stored encrypted.');
    }

    public function serverFields(): array
    {
        return [
            TextInput::make('shared_ip')
                ->label('IP for new accounts')
                ->ip()
                ->helperText(__('Leave empty to use the first IP address of the login above.')),
        ];
    }

    /** DirectAdmin's default max_username_length. */
    public function maxUsernameLength(): int
    {
        return 10;
    }
}
