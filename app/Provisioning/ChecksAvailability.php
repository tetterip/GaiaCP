<?php

namespace App\Provisioning;

/** A DomainRegistrar that says whether a domain can be registered (the client area's domain search asks it first). */
interface ChecksAvailability
{
    /**
     * @param  string  $domain  in ASCII (punycode)
     *
     * @throws ProvisioningException
     */
    public function available(string $domain): bool;
}
