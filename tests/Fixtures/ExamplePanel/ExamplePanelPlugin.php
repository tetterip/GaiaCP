<?php

namespace Tests\Fixtures\ExamplePanel;

use App\Models\Server;
use App\Plugins\Plugin;
use App\Provisioning\HostingProvisioner;
use App\Provisioning\ServerModule;
use Filament\Forms\Components\TextInput;
use Mockery;

/** A plugin as someone else would write one: a server panel GaiaCP doesn't ship, in its own folder. */
class ExamplePanelPlugin extends Plugin implements ServerModule
{
    public function id(): string
    {
        return 'example-panel';
    }

    public function name(): string
    {
        return 'Example Panel';
    }

    public function description(): string
    {
        return __('Hosting accounts on Example Panel servers.');
    }

    public function category(): string
    {
        return 'Servers';
    }

    public function key(): string
    {
        return 'example';
    }

    public function label(): string
    {
        return 'Example Panel';
    }

    public function defaultPort(): int
    {
        return 4443;
    }

    public function clientPort(): int
    {
        return 4444;
    }

    public function provisioner(Server $server): HostingProvisioner
    {
        return Mockery::mock(HostingProvisioner::class);
    }

    public function usernameRequired(): bool
    {
        return false;
    }

    public function usernameHelp(): string
    {
        return __('Example login.');
    }

    public function tokenHelp(): string
    {
        return __('Example API key.');
    }

    public function serverFields(): array
    {
        return [TextInput::make('notes')->label('Example region')];
    }
}
