<?php

namespace App\Provisioning;

/**
 * A HostingProvisioner that can say how big each of its packages is, so the package pickers show "basic · 5 GB disk ·
 * 50 GB bandwidth" rather than a bare name (App\Provisioning\PackageOptions). Read only and quick: one call with a short
 * timeout, like packages().
 */
interface DescribesPackages
{
    /**
     * Every package, by name, with its limits. Throws ProvisioningException when the panel cannot be read.
     *
     * @return array<string, PackageLimits>
     */
    public function packageLimits(): array;
}
