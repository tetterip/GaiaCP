<?php

namespace App\Provisioning;

/** A DomainRegistrar that locks a domain against transfers away (registrar lock) and says whether it is locked. */
interface ManagesRegistrarLock
{
    /** @throws ProvisioningException */
    public function locked(string $domain): bool;

    /** @throws ProvisioningException */
    public function setLocked(string $domain, bool $locked): void;
}
