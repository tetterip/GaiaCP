<?php

namespace App\Provisioning;

/**
 * A HostingProvisioner that can say how busy its server's processors are, for the CPU bar on the dashboard's Servers
 * card (App\Monitoring\ServerLoadCheck, every 5 minutes). Read only and quick: a single call with a short timeout.
 */
interface ReportsLoad
{
    /** Throws ProvisioningException when the panel cannot be read. */
    public function load(): ServerLoad;
}
