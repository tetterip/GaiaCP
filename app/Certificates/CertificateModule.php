<?php

namespace App\Certificates;

use App\Provisioning\ProvisioningException;

/**
 * An SSL certificate provider (a reseller API), provided by a plugin. A product's and a service's `package` names one
 * of its products as "<key>:<product code>"; App\Actions\CertificateLifecycle is the only code that calls it. Every
 * method throws ProvisioningException with a message for the admin when the provider says no or can't be reached.
 */
interface CertificateModule
{
    /** Stored in `certificates.provider` and in front of the product codes; never change it once services use it. */
    public function certificateKey(): string;

    public function certificateLabel(): string;

    /** Whether its login is filled in. */
    public function certificateConfigured(): bool;

    /**
     * @return array<string, CertificateProduct> product code => product, the ones it sells
     *
     * @throws ProvisioningException
     */
    public function certificateProducts(): array;

    /**
     * Orders a new certificate (charged to the reseller account), or a renewal of an earlier order when $renewOf is
     * given. Returns the new order's state, with the validation the client has to do.
     *
     * @throws ProvisioningException
     */
    public function orderCertificate(CertificateOrder $order, ?string $renewOf = null): CertificateState;

    /** @throws ProvisioningException */
    public function certificateStatus(string $orderId): CertificateState;

    /**
     * A new certificate in the same order (a new key, or before the current one ends): free within the order.
     *
     * @throws ProvisioningException
     */
    public function reissueCertificate(string $orderId, CertificateOrder $order): CertificateState;

    /**
     * Another way to validate the domain (dns, http, https or email with $approverEmail).
     *
     * @return array<string, mixed> the new validation details
     *
     * @throws ProvisioningException
     */
    public function changeValidation(string $orderId, string $domain, string $method, ?string $approverEmail = null): array;

    /** Asks the certificate authority to look for the DNS record or file again (or to resend the email). @throws ProvisioningException */
    public function recheckValidation(string $orderId, string $domain, string $method): void;

    /** @throws ProvisioningException */
    public function cancelCertificate(string $orderId, string $reason): void;
}
