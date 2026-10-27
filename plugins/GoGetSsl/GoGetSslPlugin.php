<?php

namespace Plugins\GoGetSsl;

use App\Certificates\CertificateModule;
use App\Certificates\CertificateOrder;
use App\Certificates\CertificateState;
use App\Plugins\Plugin;

/** SSL certificates bought from a GoGetSSL reseller account (GoGetSslClient). Certificate provider "gogetssl". */
class GoGetSslPlugin extends Plugin implements CertificateModule
{
    public function id(): string
    {
        return 'gogetssl';
    }

    public function name(): string
    {
        return 'GoGetSSL';
    }

    public function description(): string
    {
        return __('Sells SSL certificates (Sectigo, DigiCert, GeoTrust, RapidSSL) through a GoGetSSL reseller account.');
    }

    public function category(): string
    {
        return 'Integrations';
    }

    /** Off in a new install: only sellers of SSL certificates use it. */
    public function enabledByDefault(): bool
    {
        return false;
    }

    public function settingsPage(): ?string
    {
        return GoGetSslSettings::class;
    }

    public function pages(): array
    {
        return [GoGetSslSettings::class];
    }

    public function settingKeys(): array
    {
        return ['gogetssl.username', 'gogetssl.password', 'gogetssl.dcv_method'];
    }

    public function encryptedSettingKeys(): array
    {
        return ['gogetssl.password'];
    }

    public function moduleLogs(): array
    {
        return ['gogetssl' => 'GoGetSSL'];
    }

    public function certificateKey(): string
    {
        return 'gogetssl';
    }

    public function certificateLabel(): string
    {
        return 'GoGetSSL';
    }

    public function certificateConfigured(): bool
    {
        return filled(config('gaiacp.gogetssl.username')) && filled(config('gaiacp.gogetssl.password'));
    }

    public function certificateProducts(): array
    {
        return GoGetSslClient::fromConfig()->products();
    }

    public function orderCertificate(CertificateOrder $order, ?string $renewOf = null): CertificateState
    {
        return GoGetSslClient::fromConfig()->order($order, renewal: $renewOf !== null);
    }

    public function certificateStatus(string $orderId): CertificateState
    {
        return GoGetSslClient::fromConfig()->status($orderId);
    }

    public function reissueCertificate(string $orderId, CertificateOrder $order): CertificateState
    {
        return GoGetSslClient::fromConfig()->reissue($orderId, $order);
    }

    public function changeValidation(string $orderId, string $domain, string $method, ?string $approverEmail = null): array
    {
        return GoGetSslClient::fromConfig()->changeValidation($orderId, $domain, $method, $approverEmail);
    }

    public function recheckValidation(string $orderId, string $domain, string $method): void
    {
        GoGetSslClient::fromConfig()->recheck($orderId, $domain, $method);
    }

    public function cancelCertificate(string $orderId, string $reason): void
    {
        GoGetSslClient::fromConfig()->cancel($orderId, $reason);
    }
}
