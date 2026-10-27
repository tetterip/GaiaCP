<?php

namespace App\Certificates;

use App\Enums\CertificateStatus;
use App\Enums\ServiceStatus;
use App\Enums\ServiceType;
use App\Models\ActivityLog;
use App\Models\Certificate;
use App\Models\Service;
use App\Provisioning\InstallsCertificates;
use App\Provisioning\ProvisionerFactory;
use App\Provisioning\ProvisioningException;
use Throwable;

/**
 * An SSL certificate whose domain is the client's own active hosting account on a server GaiaCP manages
 * (Settings → Domains → Set up on our own hosting): the server's provisioner (App\Provisioning\InstallsCertificates)
 * puts the validation file or DNS record in place, so the client does nothing, and installs the certificate once it
 * is issued. Called by App\Actions\CertificateLifecycle; a failure is logged and the client validates by hand.
 */
class CertificateHosting
{
    public function __construct(private ProvisionerFactory $provisioners) {}

    public static function enabled(): bool
    {
        return (bool) config('gaiacp.domains.ssl_own_hosting', true);
    }

    /**
     * The client's active hosting account for the certificate's domain (or the domain without "www."), on a server
     * whose panel can do this.
     *
     * @return array{0: Service, 1: InstallsCertificates}|null
     */
    public function find(Service $ssl): ?array
    {
        if (! self::enabled() || blank($ssl->domain)) {
            return null;
        }

        $domain = strtolower($ssl->domain);
        $names = array_values(array_unique([$domain, preg_replace('/^www\./', '', $domain)]));

        $accounts = Service::query()
            ->where('client_id', $ssl->client_id)
            ->where('type', ServiceType::Hosting)
            ->where('status', ServiceStatus::Active)
            ->whereNotNull('server_id')
            ->whereIn('domain', $names)
            ->with('server')
            ->get()
            ->sortBy(fn (Service $hosting) => array_search(strtolower($hosting->domain), $names, true));

        foreach ($accounts as $hosting) {
            $provisioner = rescue(fn () => $this->provisioners->forService($hosting), report: false);

            if ($provisioner instanceof InstallsCertificates) {
                return [$hosting, $provisioner];
            }
        }

        return null;
    }

    /**
     * The first of $methods (the ones the product takes, in order) that the hosting's server can do on its own.
     *
     * @param  list<string>  $methods
     */
    public function method(Service $ssl, array $methods): ?string
    {
        [, $provisioner] = $this->find($ssl) ?? [null, null];

        return $provisioner ? (array_values(array_intersect($methods, $provisioner->certificateValidationMethods()))[0] ?? null) : null;
    }

    /** Puts the validation of a certificate waiting for it on the hosting account; false when it can't (the client does it then). */
    public function placeValidation(Service $ssl): bool
    {
        $certificate = $ssl->certificate();
        $method = (string) $certificate->dcv_method;

        if ($certificate->validation_placed_at !== null) {
            $certificate->update(['validation_placed_at' => null]); // a new order, reissue or method: not in place yet
        }

        if ($certificate->status !== CertificateStatus::Pending || ! ($found = $this->find($ssl))) {
            return false;
        }

        [$hosting, $provisioner] = $found;
        $details = self::details($certificate);

        if ($details === null || ! in_array($method, $provisioner->certificateValidationMethods(), true)) {
            return false;
        }

        try {
            $provisioner->placeCertificateValidation($hosting, $method, $details);
        } catch (ProvisioningException $e) {
            $this->failed($ssl, $certificate, 'ssl.validation_placed', $e);

            return false;
        }

        $certificate->update(['hosting_service_id' => $hosting->id, 'validation_placed_at' => now(), 'error' => null]);
        ActivityLog::record('ssl.validation_placed', "SSL certificate for {$certificate->commonName()}: validation by {$method} put in place on hosting {$hosting->domain} ({$hosting->server?->name})", $ssl, properties: ['method' => $method, 'hosting_service_id' => $hosting->id]);

        return true;
    }

    /** Whether an issued certificate can be installed: GaiaCP has its private key and the client has the hosting. */
    public function canInstall(Service $ssl): bool
    {
        $certificate = $ssl->certificateDetails;

        return $certificate?->status === CertificateStatus::Issued && filled($certificate->certificate) && filled($certificate->private_key)
            && $this->find($ssl) !== null;
    }

    /**
     * Installs the issued certificate on the hosting account. Returns false when there is nothing to install it on;
     * a panel failure is logged, then thrown when $throw, else false.
     *
     * @throws ProvisioningException
     */
    public function install(Service $ssl, bool $throw = false): bool
    {
        $certificate = $ssl->certificate();

        if ($certificate->status !== CertificateStatus::Issued || blank($certificate->certificate) || blank($certificate->private_key)
            || ! ($found = $this->find($ssl))) {
            return $throw ? throw new ProvisioningException('There is no issued certificate with its private key, or no active hosting account of the client for this domain on a cPanel or Plesk server.') : false;
        }

        [$hosting, $provisioner] = $found;

        try {
            $provisioner->installCertificate($hosting, ltrim($certificate->commonName(), '*.'), (string) $certificate->certificate, (string) $certificate->private_key, $certificate->ca_bundle);
        } catch (ProvisioningException $e) {
            $this->failed($ssl, $certificate, 'ssl.installed', $e);

            return $throw ? throw $e : false;
        }

        $certificate->update(['hosting_service_id' => $hosting->id, 'installed_at' => now(), 'error' => null]);
        ActivityLog::record('ssl.installed', "SSL certificate for {$certificate->commonName()} installed on hosting {$hosting->domain} ({$hosting->server?->name})", $ssl, properties: ['hosting_service_id' => $hosting->id]);

        return true;
    }

    /**
     * The provider's validation details in the shape InstallsCertificates takes: the file's path under
     * /.well-known/pki-validation/ and contents, or the DNS record's name, type and value. Null when they are missing
     * or don't look right.
     *
     * @return array{path?: string, content?: string, name?: string, type?: string, value?: string}|null
     */
    public static function details(Certificate $certificate): ?array
    {
        $method = (string) $certificate->dcv_method;
        $validation = (array) ($certificate->validation ?? []);
        $domain = strtolower(ltrim($certificate->commonName(), '*.'));

        if (in_array($method, ['http', 'https'], true)) {
            $link = (string) data_get($validation, "{$method}.link", '');
            $path = (string) parse_url($link, PHP_URL_PATH);
            $host = strtolower((string) parse_url($link, PHP_URL_HOST));
            $content = (string) data_get($validation, "{$method}.content", '');

            return preg_match('#^/\.well-known/pki-validation/[A-Za-z0-9._-]+$#', $path) && ! str_contains($path, '..') && $content !== ''
                && in_array($host, [$domain, "www.{$domain}"], true)
                ? ['path' => $path, 'content' => $content]
                : null;
        }

        if ($method === 'dns') {
            // "_abc.example.com CNAME xyz.sectigo.com" (some providers leave the type out)
            $parts = preg_split('/\s+/', trim((string) data_get($validation, 'dns.record', ''))) ?: [];
            $parts = array_values(array_filter($parts, fn (string $part) => $part !== '' && strtoupper($part) !== 'CNAME'));

            if (count($parts) !== 2) {
                return null;
            }

            $name = strtolower(rtrim($parts[0], '.'));
            $base = preg_replace('/^www\./', '', $domain);

            return str_ends_with($name, '.'.$base) ? ['name' => $name, 'type' => 'CNAME', 'value' => rtrim($parts[1], '.')] : null;
        }

        return null;
    }

    private function failed(Service $ssl, Certificate $certificate, string $event, Throwable $e): void
    {
        $certificate->update(['error' => mb_substr($e->getMessage(), 0, 255)]);
        ActivityLog::record('provisioning.failed', "{$event} failed: {$e->getMessage()}", $ssl, properties: ['event' => $event]);
    }
}
