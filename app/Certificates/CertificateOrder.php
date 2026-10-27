<?php

namespace App\Certificates;

/** What a new order, a renewal or a reissue sends to the provider. */
final readonly class CertificateOrder
{
    /**
     * @param  array<string, string>  $contact  first_name, last_name, email, phone, company, address, city, postcode,
     *                                          country (ISO code), the client's, for the admin and technical contacts
     */
    public function __construct(
        public string $productCode,
        public int $months,
        public string $csr,
        public string $domain,
        public string $method,
        public ?string $approverEmail,
        public array $contact,
        public bool $organization = false,
    ) {}
}
