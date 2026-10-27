<?php

namespace App\Provisioning;

/**
 * A DomainRegistrar that reads and changes a domain's contacts (the domain's Contact details). Registrars without it
 * only get the client's details as every contact at registration and transfer.
 */
interface ManagesDomainContacts
{
    /** @return list<string> the roles (DomainContact::ROLES keys) this registrar keeps, so forms show them without a call */
    public function contactRoles(): array;

    /**
     * @return array<string, DomainContact> by role (DomainContact::ROLES), the ones the registrar has
     *
     * @throws ProvisioningException
     */
    public function contacts(string $domain): array;

    /**
     * @param  array<string, DomainContact>  $contacts  by role, the ones contacts() gave
     *
     * @throws ProvisioningException
     */
    public function saveContacts(string $domain, array $contacts): void;
}
