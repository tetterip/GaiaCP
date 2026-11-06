<?php

namespace App\Provisioning;

use App\Models\Service;

/**
 * A HostingProvisioner whose accounts are machines (a Proxmox VPS), not websites: the service pages show the machine
 * (ServiceLifecycle::machine()) in place of disk and bandwidth usage, its welcome email is the VPS Welcome Email, and
 * there is no "Open website".
 */
interface DescribesMachine
{
    /** Its state, size and addresses. Throws ProvisioningException when it cannot be read. */
    public function machine(Service $service): MachineDetails;

    /** The user that logs in inside the machine with the service's password (root on most images). */
    public function machineUser(): string;

    /**
     * How the client signs in to the panel's web interface with the service's username and password, translated
     * (e.g. which realm to pick), or null when nothing more is needed.
     */
    public function panelLoginHint(Service $service): ?string;
}
