<?php

namespace App\Filament\Certificates;

use App\Enums\CertificateStatus;
use App\Models\Service;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipArchive;

/** An issued certificate as a zip: the certificate, the CA bundle, the private key GaiaCP made (if it did) and the CSR. */
class CertificateDownload
{
    public static function available(Service $record): bool
    {
        $certificate = $record->certificateDetails;

        return $certificate !== null && $certificate->status === CertificateStatus::Issued && filled($certificate->certificate);
    }

    public static function response(Service $record): StreamedResponse
    {
        $certificate = $record->certificateDetails;
        $name = str_replace('*', 'wildcard', $certificate->commonName());
        $path = tempnam(sys_get_temp_dir(), 'ssl');

        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString("{$name}.crt", (string) $certificate->certificate);

        if (filled($certificate->ca_bundle)) {
            $zip->addFromString("{$name}.ca-bundle", (string) $certificate->ca_bundle);
        }

        if (filled($certificate->private_key)) {
            $zip->addFromString("{$name}.key", (string) $certificate->private_key);
        }

        if (filled($certificate->csr)) {
            $zip->addFromString("{$name}.csr", (string) $certificate->csr);
        }

        $zip->close();

        return response()->streamDownload(function () use ($path) {
            readfile($path);
            @unlink($path);
        }, "{$name}-ssl.zip", ['Content-Type' => 'application/zip']);
    }
}
