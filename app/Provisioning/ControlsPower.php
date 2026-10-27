<?php

namespace App\Provisioning;

use App\Models\Service;

/**
 * A HostingProvisioner whose accounts are machines that can be started and stopped (Proxmox VPS): the admin's Account
 * menu and, when clientsControlPower(), the client's service page offer the actions (ServiceLifecycle::power()).
 */
interface ControlsPower
{
    /** start, a clean shutdown, a restart and a hard stop, in display order. */
    public const ACTIONS = ['start', 'shutdown', 'reboot', 'stop'];

    public function isRunning(Service $service): bool;

    /** @param  value-of<self::ACTIONS>  $action */
    public function power(Service $service, string $action): void;

    /** Whether clients get the buttons too (the plugin's own switch). */
    public function clientsControlPower(): bool;
}
