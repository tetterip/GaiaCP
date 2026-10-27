<?php

namespace App\Provisioning;

/**
 * A DomainRegistrar that registers private nameservers (glue records such as ns1.example.com) under a domain, so the
 * domain or others can use them. Registrars can't list them, so GaiaCP keeps the ones made here (`domains.child_nameservers`).
 */
interface ManagesChildNameservers
{
    /**
     * Register the host with this IP, or change its IP ($oldIp: the one it had, when known).
     *
     * @throws ProvisioningException
     */
    public function saveChildNameserver(string $domain, string $host, string $ip, ?string $oldIp = null): void;

    /** @throws ProvisioningException */
    public function deleteChildNameserver(string $domain, string $host): void;
}
