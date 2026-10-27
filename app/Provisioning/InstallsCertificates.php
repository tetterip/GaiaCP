<?php

namespace App\Provisioning;

use App\Models\Service;

/**
 * A HostingProvisioner that can validate an SSL certificate's domain for the client and install the issued
 * certificate on a hosting account (App\Certificates\CertificateHosting), when the certificate's domain is hosted on
 * the server.
 */
interface InstallsCertificates
{
    /** @return list<string> the validation methods it can do on its own: http / https (a file), dns (a record) */
    public function certificateValidationMethods(): array;

    /**
     * Puts the file or DNS record that proves the domain in place. Safe to re-run. $details is, for http / https,
     * `path` (under /.well-known/pki-validation/) and `content`; for dns, the record's `name`, `type` and `value`.
     *
     * @param  array<string, string>  $details
     *
     * @throws ProvisioningException
     */
    public function placeCertificateValidation(Service $hosting, string $method, array $details): void;

    /**
     * Installs the certificate for $domain (no "*.") on the account and uses it for the website.
     *
     * @throws ProvisioningException
     */
    public function installCertificate(Service $hosting, string $domain, string $certificate, string $privateKey, ?string $caBundle): void;
}
