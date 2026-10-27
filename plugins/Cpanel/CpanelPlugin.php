<?php

namespace Plugins\Cpanel;

use App\Models\Server;
use App\Plugins\Plugin;
use App\Provisioning\HostingProvisioner;
use App\Provisioning\ServerModule;

/** cPanel / WHM hosting accounts over WHM API 1 (WhmClient, CpanelProvisioner). Servers of type "cpanel". */
class CpanelPlugin extends Plugin implements ServerModule
{
    public function id(): string
    {
        return 'cpanel';
    }

    public function name(): string
    {
        return 'cPanel / WHM';
    }

    public function description(): string
    {
        return __('Creates, suspends and removes hosting accounts on cPanel servers through WHM.');
    }

    public function category(): string
    {
        return 'Servers';
    }

    public function moduleLogs(): array
    {
        return ['cpanel' => 'cPanel/WHM'];
    }

    public function key(): string
    {
        return 'cpanel';
    }

    public function label(): string
    {
        return 'cPanel / WHM';
    }

    public function defaultPort(): int
    {
        return 2087;
    }

    public function clientPort(): int
    {
        return 2083;
    }

    public function provisioner(Server $server): HostingProvisioner
    {
        return new CpanelProvisioner(new WhmClient($server));
    }

    public function usernameRequired(): bool
    {
        return true;
    }

    public function usernameHelp(): string
    {
        return __('WHM user: root or a reseller.');
    }

    public function tokenHelp(): string
    {
        return __('WHM: Development → Manage API Tokens. Stored encrypted.');
    }

    public function serverFields(): array
    {
        return [];
    }
}
