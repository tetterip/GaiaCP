<?php

namespace Plugins\CyberPanel;

use App\Models\Server;
use App\Plugins\Plugin;
use App\Provisioning\HostingProvisioner;
use App\Provisioning\ServerModule;

/** CyberPanel websites and their owner users (CyberPanelClient, CyberPanelProvisioner). Servers of type "cyberpanel". */
class CyberPanelPlugin extends Plugin implements ServerModule
{
    public function id(): string
    {
        return 'cyberpanel';
    }

    public function name(): string
    {
        return 'CyberPanel';
    }

    public function description(): string
    {
        return __('Creates, suspends and removes hosting accounts on CyberPanel servers.');
    }

    public function category(): string
    {
        return 'Servers';
    }

    /** Off in a new install: only some sellers run CyberPanel. */
    public function enabledByDefault(): bool
    {
        return false;
    }

    public function settingsPage(): ?string
    {
        return CyberPanelSettings::class;
    }

    public function pages(): array
    {
        return [CyberPanelSettings::class];
    }

    public function settingKeys(): array
    {
        return ['cyberpanel.php_version'];
    }

    public function moduleLogs(): array
    {
        return ['cyberpanel' => 'CyberPanel'];
    }

    public function key(): string
    {
        return 'cyberpanel';
    }

    public function label(): string
    {
        return 'CyberPanel';
    }

    public function defaultPort(): int
    {
        return 8090;
    }

    public function clientPort(): int
    {
        return 8090;
    }

    public function provisioner(Server $server): HostingProvisioner
    {
        return new CyberPanelProvisioner(new CyberPanelClient($server), $server);
    }

    public function usernameRequired(): bool
    {
        return true;
    }

    public function usernameHelp(): string
    {
        return __('CyberPanel\'s admin user (admin), with API access turned on under Users → API Access. New websites and their owners are created under it.');
    }

    public function tokenHelp(): string
    {
        return __('CyberPanel: the admin user\'s password (CyberPanel\'s API takes no other key). Two-factor authentication must be off for it. Stored encrypted.');
    }

    public function serverFields(): array
    {
        return [];
    }
}
