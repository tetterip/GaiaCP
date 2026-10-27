<?php

namespace App\Certificates;

use App\Provisioning\ProvisioningException;

/** Makes the private key and certificate signing request (CSR) of a certificate, so clients never have to. */
class Csr
{
    /**
     * @param  array<string, string>  $contact  as in CertificateOrder
     * @return array{csr: string, key: string}
     *
     * @throws ProvisioningException
     */
    public static function make(string $commonName, array $contact): array
    {
        $config = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'digest_alg' => 'sha256'];
        $key = openssl_pkey_new($config);

        $subject = array_filter([
            'commonName' => $commonName,
            'organizationName' => $contact['company'] ?? null,
            'localityName' => $contact['city'] ?? null,
            'countryName' => strlen((string) ($contact['country'] ?? '')) === 2 ? strtoupper($contact['country']) : null,
        ], fn ($value) => filled($value));

        if ($key === false || ($csr = openssl_csr_new($subject, $key, $config)) === false
            || ! openssl_csr_export($csr, $csrText) || ! openssl_pkey_export($key, $keyText)) {
            throw new ProvisioningException('Could not make the certificate request: '.(openssl_error_string() ?: 'OpenSSL failed'));
        }

        return ['csr' => $csrText, 'key' => $keyText];
    }

    /** The common name in a CSR the client sent, or null when it is not a CSR. */
    public static function commonName(string $csr): ?string
    {
        $subject = @openssl_csr_get_subject(trim($csr), false);

        return is_array($subject) && filled($subject['commonName'] ?? null) ? strtolower((string) $subject['commonName']) : null;
    }
}
