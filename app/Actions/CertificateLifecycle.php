<?php

namespace App\Actions;

use App\Certificates\CertificateModule;
use App\Certificates\CertificateOrder;
use App\Certificates\CertificateProduct;
use App\Certificates\CertificateState;
use App\Certificates\Csr;
use App\Enums\CertificateStatus;
use App\Enums\MailTemplate;
use App\Enums\ServiceStatus;
use App\Enums\ServiceType;
use App\Mail\ClientMailer;
use App\Models\ActivityLog;
use App\Models\Certificate;
use App\Models\Service;
use App\Plugins\PluginManager;
use App\Providers\AppServiceProvider;
use App\Provisioning\ProvisioningException;
use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * SSL certificate services (type ssl): the only code that calls a certificate provider (App\Certificates\
 * CertificateModule). The service's `package` names the provider and its product ("gogetssl:45"). GaiaCP makes the
 * private key and CSR unless the client sends their own. Every outcome, failures included, lands in the activity log;
 * a failed call leaves things as they were and throws ProvisioningException.
 */
class CertificateLifecycle
{
    /** Validation methods, in the order they are offered. */
    public const METHODS = ['dns', 'http', 'email'];

    /** Reissue (inside the order) or renew this many days before the certificate or the order ends. */
    public const DAYS_BEFORE = 30;

    private const PRODUCTS_MINUTES = 60;

    public function __construct(private PluginManager $plugins, private ClientMailer $mailer) {}

    /** @return array{0: string, 1: string}|null [provider key, product code] of a package like "gogetssl:45" */
    public static function package(?string $package): ?array
    {
        return preg_match('/^([a-z0-9_]+):(.+)$/', (string) $package, $match) ? [$match[1], $match[2]] : null;
    }

    /** @return array<string, string> package => "Provider: product", of every enabled provider (for the product and service forms) */
    public function productOptions(): array
    {
        $options = [];

        foreach ($this->plugins->certificateModules() as $key => $module) {
            foreach (rescue(fn () => $this->products($module), [], report: false) as $code => $product) {
                $options["{$key}:{$code}"] = $module->certificateLabel().': '.$product->name;
            }
        }

        return $options;
    }

    /**
     * @return array<string, CertificateProduct> the provider's products, kept for an hour
     *
     * @throws ProvisioningException
     */
    public function products(CertificateModule $module): array
    {
        $cached = Cache::remember('certificate_products.'.$module->certificateKey(), now()->addMinutes(self::PRODUCTS_MINUTES),
            fn () => array_map(fn (CertificateProduct $product) => $product->toArray(), $module->certificateProducts()));

        return array_map(fn (array $product) => CertificateProduct::fromArray($product), $cached);
    }

    public function forgetProducts(CertificateModule $module): void
    {
        Cache::forget('certificate_products.'.$module->certificateKey());
    }

    /** The service's product at its provider, when the provider still sells it (null when it can't be read). */
    public function product(Service $service): ?CertificateProduct
    {
        [$key, $code] = self::package($service->package) ?? [null, null];
        $module = $key ? $this->plugins->certificateModules()->get($key) : null;

        return $module ? (rescue(fn () => $this->products($module), [], report: false)[$code] ?? null) : null;
    }

    /**
     * Orders a pending service's certificate and makes the service active. Safe to re-run: one already ordered is
     * only read again.
     *
     * @param  string|null  $csr  the client's own CSR; null = GaiaCP makes the key and CSR
     */
    public function order(Service $service, ?string $csr = null, ?string $method = null): void
    {
        $this->assertSsl($service);
        $certificate = $service->certificate();

        if ($certificate->ordered()) {
            $this->refresh($service);

            if ($service->status === ServiceStatus::Pending) {
                $service->update(['status' => ServiceStatus::Active]);
            }

            return;
        }

        [$module, $code] = $this->moduleFor($service, 'ssl.ordered');
        $product = $this->products($module)[$code] ?? $this->fail($service, 'ssl.ordered', "{$module->certificateLabel()} does not sell product {$code} any more: pick another on the service.");
        $method = $this->method($product, $method ?? $certificate->dcv_method ?? $this->defaultMethod($module));
        $certificate->fill(['provider' => $module->certificateKey(), 'product_code' => $code, 'wildcard' => $product->wildcard, 'dcv_method' => $method]);
        $this->newKey($service, $certificate, $csr);

        $state = $this->call($service, 'ssl.ordered', fn () => $module->orderCertificate($this->request($service, $certificate, $product, $method)));

        $this->store($certificate, $state, ['remote_id' => $state->orderId, 'error' => null]);
        $service->update(['status' => ServiceStatus::Active]);

        ActivityLog::record('ssl.ordered', "SSL certificate for {$certificate->commonName()} ordered from {$module->certificateLabel()} (order {$state->orderId})", $service, properties: ['order_id' => $state->orderId, 'method' => $method]);
        $this->emailValidation($service);
    }

    /** Reads the order from the provider; the first time it is issued, the client gets it by email and in the bell. */
    public function refresh(Service $service): void
    {
        $this->assertSsl($service);
        $certificate = $service->certificate();

        if (blank($certificate->remote_id)) {
            throw new ProvisioningException('This certificate has not been ordered yet.');
        }

        $module = $this->moduleOf($service, $certificate, 'ssl.checked');
        $wasIssued = $certificate->status === CertificateStatus::Issued ? $certificate->certificate : null;
        $state = $this->call($service, 'ssl.checked', fn () => $module->certificateStatus($certificate->remote_id));
        $this->store($certificate, $state);

        if ($state->status === CertificateStatus::Issued && filled($certificate->certificate) && $certificate->certificate !== $wasIssued) {
            $certificate->update(['issued_at' => now()]);
            ActivityLog::record('ssl.issued', "SSL certificate for {$certificate->commonName()} issued, valid until {$certificate->expires_at?->format('Y-m-d')}", $service, properties: ['order_id' => $certificate->remote_id]);
            $this->mailer->service(MailTemplate::SslIssued, $service, ['expiry_date' => $certificate->expires_at?->format(AppServiceProvider::dateFormat())]);
        }
    }

    /** Validates the domain another way (or with another approver email). */
    public function changeValidation(Service $service, string $method, ?string $approverEmail = null): void
    {
        $certificate = $this->pendingCertificate($service);
        $module = $this->moduleOf($service, $certificate, 'ssl.validation_changed');
        $method = $this->method($this->products($module)[$certificate->product_code] ?? null, $method);
        $approverEmail = $method === 'email' ? ($approverEmail ?: $this->defaultApprover($service)) : null;

        $validation = $this->call($service, 'ssl.validation_changed', fn () => $module->changeValidation($certificate->remote_id, $certificate->commonName(), $method, $approverEmail));
        $certificate->update(['dcv_method' => $method, 'approver_email' => $approverEmail, 'validation' => $validation ?: $certificate->validation]);

        ActivityLog::record('ssl.validation_changed', "SSL certificate for {$certificate->commonName()}: validation by {$method}", $service, properties: ['method' => $method]);
        $this->emailValidation($service);
    }

    /** Asks the certificate authority to look again (DNS record or file), or to resend the approval email. */
    public function recheck(Service $service): void
    {
        $certificate = $this->pendingCertificate($service);
        $module = $this->moduleOf($service, $certificate, 'ssl.rechecked');

        $this->call($service, 'ssl.rechecked', fn () => $module->recheckValidation($certificate->remote_id, $certificate->commonName(), (string) $certificate->dcv_method));
        ActivityLog::record('ssl.rechecked', "SSL certificate for {$certificate->commonName()}: validation checked again", $service);
    }

    /**
     * A new certificate in the same order: with a new key (made by GaiaCP, or the client's CSR), e.g. when the key
     * was lost or the current certificate is about to end inside a longer order.
     */
    public function reissue(Service $service, ?string $csr = null): void
    {
        $this->assertSsl($service);
        $certificate = $service->certificate();

        if (! $certificate->ordered()) {
            throw new ProvisioningException('Only a certificate that is ordered can be reissued.');
        }

        $module = $this->moduleOf($service, $certificate, 'ssl.reissued');
        $product = $this->products($module)[$certificate->product_code] ?? new CertificateProduct((string) $certificate->product_code, (string) $certificate->product_code, $certificate->wildcard);
        $method = $this->method($product, $certificate->dcv_method ?? $this->defaultMethod($module));
        $previous = $certificate->only(['csr', 'private_key']);
        $this->newKey($service, $certificate, $csr);

        try {
            $state = $this->call($service, 'ssl.reissued', fn () => $module->reissueCertificate($certificate->remote_id, $this->request($service, $certificate, $product, $method)));
        } catch (ProvisioningException $e) {
            $certificate->fill($previous); // the current certificate keeps working with its own key

            throw $e;
        }

        $this->store($certificate, $state, ['status' => CertificateStatus::Pending, 'error' => null]);
        ActivityLog::record('ssl.reissued', "SSL certificate for {$certificate->commonName()} reissue requested", $service, properties: ['order_id' => $certificate->remote_id]);
        $this->emailValidation($service);
    }

    /** A new order for the next period (charged to the reseller account): only when the service is paid beyond the order's end. */
    public function renew(Service $service): void
    {
        $this->assertSsl($service);
        $certificate = $service->certificate();

        if (blank($certificate->remote_id)) {
            throw new ProvisioningException('This certificate has not been ordered yet.');
        }

        $module = $this->moduleOf($service, $certificate, 'ssl.renewed');
        $product = $this->products($module)[$certificate->product_code] ?? $this->fail($service, 'ssl.renewed', "{$module->certificateLabel()} does not sell product {$certificate->product_code} any more: pick another on the service.");
        $method = $this->method($product, $certificate->dcv_method ?? $this->defaultMethod($module));
        $previous = $certificate->remote_id;
        $this->newKey($service, $certificate, null);

        $state = $this->call($service, 'ssl.renewed', fn () => $module->orderCertificate($this->request($service, $certificate, $product, $method), $previous));

        $this->store($certificate, $state, [
            'remote_id' => $state->orderId,
            'previous_orders' => [...($certificate->previous_orders ?? []), $previous],
            'ends_at' => $state->endsAt, // read again once the new order is under way
            'error' => null,
        ]);
        ActivityLog::record('ssl.renewed', "SSL certificate for {$certificate->commonName()} renewed (order {$state->orderId})", $service, properties: ['order_id' => $state->orderId, 'previous_order_id' => $previous]);
        $this->emailValidation($service);
    }

    /** Cancels the order at the provider (a refund only within its refund days). The service itself is left alone. */
    public function cancel(Service $service, string $reason): void
    {
        $this->assertSsl($service);
        $certificate = $service->certificate();

        if (! $certificate->ordered()) {
            throw new ProvisioningException('Only a certificate that is ordered can be cancelled.');
        }

        $module = $this->moduleOf($service, $certificate, 'ssl.cancelled');
        $this->call($service, 'ssl.cancelled', fn () => $module->cancelCertificate($certificate->remote_id, $reason));
        $certificate->update(['status' => CertificateStatus::Cancelled]);

        ActivityLog::record('ssl.cancelled', "SSL certificate for {$certificate->commonName()} cancelled: {$reason}", $service, properties: ['order_id' => $certificate->remote_id]);
    }

    /**
     * What the client does to prove the domain is theirs, one line each, for the client area and the email.
     *
     * @return list<array{label: string, value: string}>
     */
    public static function instructions(?Certificate $certificate): array
    {
        if ($certificate === null) {
            return [];
        }

        $validation = (array) ($certificate->validation ?? []);

        return match ($certificate->dcv_method) {
            'dns' => self::dnsLines((string) data_get($validation, 'dns.record', '')),
            'http', 'https' => array_values(array_filter([
                ['label' => __('File address'), 'value' => (string) data_get($validation, $certificate->dcv_method.'.link', '')],
                ['label' => __('File contents'), 'value' => (string) data_get($validation, $certificate->dcv_method.'.content', '')],
            ], fn (array $line) => $line['value'] !== '')),
            'email' => array_values(array_filter([
                ['label' => __('Approval email to'), 'value' => (string) (data_get($validation, 'email.email') ?: $certificate->approver_email)],
            ], fn (array $line) => $line['value'] !== '')),
            default => [],
        };
    }

    /** One sentence on what to do for the certificate's method. */
    public static function methodHelp(?string $method): string
    {
        return match ($method) {
            'dns' => __('Add this CNAME record to the domain\'s DNS zone. It is checked automatically; it may take a few hours after you add it.'),
            'http', 'https' => __('Create this file on the website, with exactly these contents, so that it opens at this address. It is checked automatically.'),
            'email' => __('The certificate authority sends an email to this address: open the link in it and approve the certificate.'),
            default => '',
        };
    }

    public static function methodLabel(?string $method): string
    {
        return match ($method) {
            'dns' => __('DNS record (CNAME)'),
            'http' => __('File on the website (HTTP)'),
            'https' => __('File on the website (HTTPS)'),
            'email' => __('Email to the domain\'s administrator'),
            default => '—',
        };
    }

    /** @return list<array{label: string, value: string}> */
    private static function dnsLines(string $record): array
    {
        if ($record === '') {
            return [];
        }

        // "_abc.example.com CNAME xyz.sectigo.com" (some providers leave the type out)
        $parts = preg_split('/\s+/', trim($record)) ?: [];
        $parts = array_values(array_filter($parts, fn (string $part) => strtoupper($part) !== 'CNAME'));

        return count($parts) === 2
            ? [['label' => __('Name'), 'value' => rtrim($parts[0], '.')], ['label' => __('Type'), 'value' => 'CNAME'], ['label' => __('Value'), 'value' => rtrim($parts[1], '.')]]
            : [['label' => __('Record'), 'value' => $record]];
    }

    /** Emails the client what to do, while the certificate waits for validation. */
    private function emailValidation(Service $service): void
    {
        $certificate = $service->certificate();

        if ($certificate->status !== CertificateStatus::Pending) {
            return;
        }

        $this->mailer->service(MailTemplate::SslValidation, $service, fn () => [
            'validation_method' => self::methodLabel($certificate->dcv_method),
            'validation' => self::methodHelp($certificate->dcv_method)."\n\n".collect(self::instructions($certificate))
                ->map(fn (array $line) => "{$line['label']}: `{$line['value']}`")->join("<br>\n"),
        ]);
    }

    /** @param array<string, mixed> $extra */
    private function store(Certificate $certificate, CertificateState $state, array $extra = []): void
    {
        $certificate->fill(array_filter([
            'status' => $state->status,
            'dcv_method' => $state->method,
            'validation' => $state->validation ?: null,
            'certificate' => $state->certificate,
            'ca_bundle' => $state->caBundle,
            'expires_at' => $state->validTill,
            'ends_at' => $state->endsAt,
        ], fn ($value) => $value !== null))->fill($extra + ['last_checked_at' => now()])->save();
    }

    /** A new private key and CSR (or the client's CSR, then no key is kept). */
    private function newKey(Service $service, Certificate $certificate, ?string $csr): void
    {
        if (filled($csr)) {
            $name = Csr::commonName($csr) ?? throw new ProvisioningException('That is not a certificate signing request (CSR). Paste the whole text, from -----BEGIN CERTIFICATE REQUEST----- to the end.');

            if ($name !== strtolower($certificate->commonName())) {
                throw new ProvisioningException("The CSR is for {$name}, but this certificate is for {$certificate->commonName()}.");
            }

            $certificate->fill(['csr' => trim($csr), 'private_key' => null]);

            return;
        }

        $made = Csr::make($certificate->commonName(), $this->contact($service));
        $certificate->fill(['csr' => $made['csr'], 'private_key' => $made['key']]);
    }

    private function request(Service $service, Certificate $certificate, CertificateProduct $product, string $method): CertificateOrder
    {
        return new CertificateOrder(
            productCode: $product->code,
            months: $this->months($service, $product),
            csr: (string) $certificate->csr,
            domain: $certificate->commonName(),
            method: $method,
            approverEmail: $method === 'email' ? ($certificate->approver_email ?: $this->defaultApprover($service)) : null,
            contact: $this->contact($service),
            organization: $product->organization,
        );
    }

    /** The order's length: the longest the product sells within one billing cycle (a monthly service still needs a yearly product). */
    private function months(Service $service, CertificateProduct $product): int
    {
        $months = $service->billing_cycle->months();
        $fits = array_filter($product->periods, fn (int $period) => $period <= $months);

        return $fits !== [] ? max($fits) : throw new ProvisioningException("{$product->name} is sold for at least ".min($product->periods ?: [12])." months, longer than this service's billing cycle. Bill it {$this->cycleFor($product)} or pick another product.");
    }

    private function cycleFor(CertificateProduct $product): string
    {
        return min($product->periods ?: [12]) <= 12 ? 'annually' : 'every '.intdiv(min($product->periods), 12).' years';
    }

    /** @return array<string, string> */
    private function contact(Service $service): array
    {
        $client = $service->client;

        return array_map(fn ($value) => trim((string) $value), [
            'first_name' => $client->first_name,
            'last_name' => $client->last_name,
            'email' => $client->email,
            'phone' => $client->phone,
            'company' => $client->company,
            'address' => trim($client->address1.' '.$client->address2),
            'city' => $client->city,
            'state' => $client->state,
            'postcode' => $client->postcode,
            'country' => $client->country,
        ]);
    }

    private function defaultApprover(Service $service): string
    {
        return 'admin@'.$service->domain;
    }

    private function defaultMethod(CertificateModule $module): string
    {
        return (string) (config('gaiacp.'.$module->certificateKey().'.dcv_method') ?: 'dns');
    }

    /** The method if the product takes it, else the first it takes. */
    private function method(?CertificateProduct $product, string $method): string
    {
        $methods = $product?->methods ?: self::METHODS;

        // A wildcard can't be validated with a file.
        if ($product?->wildcard) {
            $methods = array_values(array_diff($methods, ['http', 'https'])) ?: ['dns'];
        }

        return in_array($method, $methods, true) ? $method : $methods[0];
    }

    private function pendingCertificate(Service $service): Certificate
    {
        $this->assertSsl($service);
        $certificate = $service->certificate();

        if (blank($certificate->remote_id) || $certificate->status !== CertificateStatus::Pending) {
            throw new ProvisioningException('Only a certificate waiting for validation can change or check it.');
        }

        return $certificate;
    }

    /** @return array{0: CertificateModule, 1: string} the provider and product code from the service's package */
    private function moduleFor(Service $service, string $event): array
    {
        [$key, $code] = self::package($service->package) ?? $this->fail($service, $event, 'Pick the certificate (Product) of this SSL service first.');

        return [$this->enabledModule($service, $key, $event), $code];
    }

    private function moduleOf(Service $service, Certificate $certificate, string $event): CertificateModule
    {
        return $this->enabledModule($service, (string) ($certificate->provider ?: (self::package($service->package)[0] ?? '')), $event);
    }

    private function enabledModule(Service $service, string $key, string $event): CertificateModule
    {
        $module = $this->plugins->certificateModules()->get($key) ?? $this->fail($service, $event, "The SSL provider \"{$key}\" is not installed or is switched off under Settings → Plugins.");

        return $module->certificateConfigured() ? $module : $this->fail($service, $event, "Fill in the {$module->certificateLabel()} login under its settings first.");
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $call
     * @return T
     */
    private function call(Service $service, string $event, Closure $call): mixed
    {
        try {
            return $call();
        } catch (ProvisioningException $e) {
            $service->certificateDetails?->update(['error' => mb_substr($e->getMessage(), 0, 255)]);
            ActivityLog::record('provisioning.failed', "{$event} failed: {$e->getMessage()}", $service, properties: ['event' => $event]);

            throw $e;
        }
    }

    private function fail(Service $service, string $event, string $message): never
    {
        ActivityLog::record('provisioning.failed', "{$event} failed: {$message}", $service, properties: ['event' => $event]);

        throw new ProvisioningException($message);
    }

    private function assertSsl(Service $service): void
    {
        if ($service->type !== ServiceType::Ssl) {
            throw new ProvisioningException('This is not an SSL certificate service.');
        }
    }
}
