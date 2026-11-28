<?php

namespace App\Provisioning;

use App\Models\Service;

/**
 * A HostingProvisioner that can have the panel issue a free certificate (Let's Encrypt) for a hosting account's
 * domain, as the Account menu's "Free SSL (Let's Encrypt)" does.
 */
interface IssuesFreeCertificates
{
    /**
     * Issues the certificate for the account's domain (or renews the one it has) and uses it for the website, and for
     * its mail when the panel can. The domain must already point at the server. Safe to re-run.
     *
     * @return list<string> the names it now covers (the domain first, then its aliases and mail name)
     *
     * @throws ProvisioningException
     */
    public function issueFreeCertificate(Service $hosting): array;
}
