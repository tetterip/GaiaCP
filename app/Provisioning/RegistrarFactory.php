<?php

namespace App\Provisioning;

use App\Models\Registrar;
use App\Plugins\PluginManager;

/** Builds the DomainRegistrar for a registrar account, like ProvisionerFactory does for servers. */
class RegistrarFactory
{
    public function __construct(private PluginManager $plugins) {}

    public function forRegistrar(Registrar $registrar): DomainRegistrar
    {
        if (! $registrar->active) {
            throw new ProvisioningException("Registrar {$registrar->name} is inactive.");
        }

        return $this->make($registrar->module, [...($registrar->credentials ?? []), 'verify_tls' => $registrar->verify_tls]);
    }

    /** @param  array<string, mixed>  $credentials */
    public function make(string $module, array $credentials): DomainRegistrar
    {
        return $this->plugins->enabledRegistrarModule($module)->registrar($credentials);
    }

    /** The registrar account that handles this domain's TLD. */
    public function forDomain(string $domain): DomainRegistrar
    {
        $registrar = Registrar::forDomain($domain);

        if (! $registrar) {
            throw new ProvisioningException("No active registrar handles the TLD of {$domain}. Pick one under Settings → Domain pricing.");
        }

        return $this->forRegistrar($registrar);
    }
}
