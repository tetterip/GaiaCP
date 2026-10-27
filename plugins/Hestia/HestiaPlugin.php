<?php

namespace Plugins\Hestia;

use App\Models\Server;
use App\Plugins\Plugin;
use App\Provisioning\HostingProvisioner;
use App\Provisioning\ServerModule;

/** HestiaCP users and packages over its API (HestiaClient, HestiaProvisioner). Servers of type "hestia". */
class HestiaPlugin extends Plugin implements ServerModule
{
    public function id(): string
    {
        return 'hestia';
    }

    public function name(): string
    {
        return 'HestiaCP';
    }

    public function description(): string
    {
        return __('Creates, suspends and removes hosting accounts on HestiaCP servers.');
    }

    public function category(): string
    {
        return 'Servers';
    }

    public function moduleLogs(): array
    {
        return ['hestia' => 'HestiaCP'];
    }

    public function key(): string
    {
        return 'hestia';
    }

    public function label(): string
    {
        return 'HestiaCP';
    }

    public function defaultPort(): int
    {
        return 8083;
    }

    public function clientPort(): int
    {
        return 8083;
    }

    public function provisioner(Server $server): HostingProvisioner
    {
        return new HestiaProvisioner(new HestiaClient($server), $server);
    }

    /** Empty = authenticate with an access key. */
    public function usernameRequired(): bool
    {
        return false;
    }

    public function usernameHelp(): string
    {
        return __('Leave empty to use an access key (recommended). Fill in the admin user (admin) only to log in with its password instead.');
    }

    public function tokenHelp(): string
    {
        return __('HestiaCP: an access key of the admin user, as ACCESS_KEY:SECRET_KEY (admin → Access keys, all permissions), or the admin password if the username is filled in. Stored encrypted.');
    }

    public function serverFields(): array
    {
        return [];
    }
}
