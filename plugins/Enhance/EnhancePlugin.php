<?php

namespace Plugins\Enhance;

use App\Models\Server;
use App\Plugins\Plugin;
use App\Provisioning\HostingProvisioner;
use App\Provisioning\ServerModule;

/** Enhance customers, subscriptions and websites over its REST API (EnhanceClient, EnhanceProvisioner). Servers of type "enhance". */
class EnhancePlugin extends Plugin implements ServerModule
{
    public function id(): string
    {
        return 'enhance';
    }

    public function name(): string
    {
        return 'Enhance';
    }

    public function description(): string
    {
        return __('Creates, suspends and removes hosting subscriptions on Enhance control panels.');
    }

    public function category(): string
    {
        return 'Servers';
    }

    /** Off until someone who runs Enhance switches it on. */
    public function enabledByDefault(): bool
    {
        return false;
    }

    public function moduleLogs(): array
    {
        return ['enhance' => 'Enhance'];
    }

    public function key(): string
    {
        return 'enhance';
    }

    public function label(): string
    {
        return 'Enhance';
    }

    public function defaultPort(): int
    {
        return 443;
    }

    public function clientPort(): int
    {
        return 443;
    }

    public function provisioner(Server $server): HostingProvisioner
    {
        return new EnhanceProvisioner(new EnhanceClient($server), $server);
    }

    public function usernameRequired(): bool
    {
        return true;
    }

    public function usernameHelp(): string
    {
        return __('Enhance: the ID of your own organisation, shown with the access tokens. Customers are created under it, on its plans; the hostname is the control panel\'s address.');
    }

    public function tokenHelp(): string
    {
        return __('Enhance: an access token allowed to manage customers and their subscriptions (Settings → Access tokens). Stored encrypted.');
    }

    public function serverFields(): array
    {
        return [];
    }
}
