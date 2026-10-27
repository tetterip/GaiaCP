<?php

namespace App\Provisioning;

/**
 * A HostingProvisioner that can list every hosting account on its server, for Servers → Compare with the server
 * (App\Actions\CompareServerAccounts). Read only.
 */
interface ListsAccounts
{
    /**
     * @return list<PanelAccount>
     *
     * @throws ProvisioningException
     */
    public function accounts(): array;
}
