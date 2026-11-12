<?php

namespace Plugins\Virtualmin;

use App\Models\Server;
use App\Plugins\Plugin;
use App\Provisioning\HostingProvisioner;
use App\Provisioning\ServerModule;

/** Virtualmin virtual servers over its remote API (VirtualminClient, VirtualminProvisioner). Servers of type "virtualmin". */
class VirtualminPlugin extends Plugin implements ServerModule
{
    public function id(): string
    {
        return 'virtualmin';
    }

    public function name(): string
    {
        return 'Virtualmin';
    }

    public function description(): string
    {
        return __('Creates, suspends and removes hosting accounts on Virtualmin servers.');
    }

    public function category(): string
    {
        return 'Servers';
    }

    /** Off in a new install: only some sellers run Virtualmin. */
    public function enabledByDefault(): bool
    {
        return false;
    }

    public function moduleLogs(): array
    {
        return ['virtualmin' => 'Virtualmin'];
    }

    public function key(): string
    {
        return 'virtualmin';
    }

    public function label(): string
    {
        return 'Virtualmin';
    }

    public function defaultPort(): int
    {
        return 10000;
    }

    public function clientPort(): int
    {
        return 10000;
    }

    public function provisioner(Server $server): HostingProvisioner
    {
        return new VirtualminProvisioner(new VirtualminClient($server), $server);
    }

    public function usernameRequired(): bool
    {
        return true;
    }

    public function usernameHelp(): string
    {
        return __('Virtualmin\'s master administrator (usually root): its remote API is open only to it.');
    }

    public function tokenHelp(): string
    {
        return __('Virtualmin: the master administrator\'s password (the remote API takes no other key). Stored encrypted.');
    }

    public function serverFields(): array
    {
        return [];
    }
}
