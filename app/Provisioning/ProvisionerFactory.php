<?php

namespace App\Provisioning;

use App\Models\Server;
use App\Models\Service;
use App\Plugins\PluginManager;

/** Builds the HostingProvisioner for a server from its type's plugin (App\Provisioning\ServerModule). */
class ProvisionerFactory
{
    public function __construct(private PluginManager $plugins) {}

    public function forServer(Server $server): HostingProvisioner
    {
        return $this->plugins->enabledServerModule((string) $server->type)->provisioner($server);
    }

    public function forService(Service $service): HostingProvisioner
    {
        if (! $service->server) {
            throw new ProvisioningException('This service has no server.');
        }

        return $this->forServer($service->server);
    }
}
