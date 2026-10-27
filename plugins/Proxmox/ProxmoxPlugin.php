<?php

namespace Plugins\Proxmox;

use App\Models\Server;
use App\Plugins\Plugin;
use App\Provisioning\HostingProvisioner;
use App\Provisioning\ServerModule;

/** Proxmox VE virtual machines sold as VPS, over its API (ProxmoxClient, ProxmoxProvisioner). Servers of type "proxmox". */
class ProxmoxPlugin extends Plugin implements ServerModule
{
    public function id(): string
    {
        return 'proxmox';
    }

    public function name(): string
    {
        return 'Proxmox VE';
    }

    public function description(): string
    {
        return __('Sells VPS on Proxmox VE: creates each one from a template, gives it an IP address, suspends, starts, stops and removes it.');
    }

    public function category(): string
    {
        return 'Servers';
    }

    /** Most installs sell shared hosting only. */
    public function enabledByDefault(): bool
    {
        return false;
    }

    public function settingsPage(): ?string
    {
        return ProxmoxSettings::class;
    }

    public function pages(): array
    {
        return [ProxmoxSettings::class, ProxmoxAddressesPage::class];
    }

    public function settingKeys(): array
    {
        return ['proxmox.ci_user', 'proxmox.client_role', 'proxmox.client_power', 'proxmox.storage', 'proxmox.nameserver'];
    }

    public function moduleLogs(): array
    {
        return ['proxmox' => 'Proxmox VE'];
    }

    public function key(): string
    {
        return 'proxmox';
    }

    public function label(): string
    {
        return 'Proxmox VE';
    }

    public function defaultPort(): int
    {
        return 8006;
    }

    public function clientPort(): int
    {
        return 8006;
    }

    public function provisioner(Server $server): HostingProvisioner
    {
        return new ProxmoxProvisioner(new ProxmoxClient($server), $server);
    }

    public function usernameRequired(): bool
    {
        return true;
    }

    public function usernameHelp(): string
    {
        return __('The API token ID, as user@realm!name (e.g. root@pam!gaiacp). Datacenter → Permissions → API Tokens.');
    }

    public function tokenHelp(): string
    {
        return __('Proxmox: the token\'s secret, shown once when it is made. Untick Privilege Separation, or give the token the Administrator role on /. Stored encrypted.');
    }

    public function serverFields(): array
    {
        return [];
    }
}
