<?php

namespace App\Provisioning;

use Filament\Schemas\Components\Component;

/**
 * A registrar API a registrar account can use (`registrars.module` = key()), provided by a plugin.
 * RegistrarFactory builds its DomainRegistrar; the registrar form takes its login fields.
 */
interface RegistrarModule
{
    /** Stored in `registrars.module`; never change it once accounts use it. */
    public function key(): string;

    public function label(): string;

    /** @param  array<string, mixed>  $credentials  the account's login fields plus verify_tls */
    public function registrar(array $credentials): DomainRegistrar;

    /** @return list<Component> the login fields, named credentials.<field>, shown only for this module */
    public function credentialFields(): array;
}
