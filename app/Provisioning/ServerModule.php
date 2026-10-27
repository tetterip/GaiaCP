<?php

namespace App\Provisioning;

use App\Models\Server;
use Filament\Schemas\Components\Component;

/**
 * A kind of hosting panel a server can be (`servers.type` = key()), provided by a plugin. ProvisionerFactory
 * builds its provisioner; the server form takes its help texts and extra fields.
 */
interface ServerModule
{
    /** Stored in `servers.type`; never change it once servers use it. */
    public function key(): string;

    public function label(): string;

    /** The API port a new server starts with. */
    public function defaultPort(): int;

    /** The port clients log in to the control panel on, for the welcome email. */
    public function clientPort(): int;

    public function provisioner(Server $server): HostingProvisioner;

    public function usernameRequired(): bool;

    /** Help under the server's username, translated. */
    public function usernameHelp(): string;

    /** Help under the API token / password, translated. */
    public function tokenHelp(): string;

    /** @return list<Component> more fields on the server form, shown only for this type */
    public function serverFields(): array;
}
