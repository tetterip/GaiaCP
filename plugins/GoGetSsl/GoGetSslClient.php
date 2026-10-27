<?php

namespace Plugins\GoGetSsl;

use App\Certificates\CertificateOrder;
use App\Certificates\CertificateProduct;
use App\Certificates\CertificateState;
use App\Enums\CertificateStatus;
use App\Provisioning\ProvisioningException;
use App\Support\ModuleLogger;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * GoGetSSL's reseller API (my.gogetssl.com/api; it has no test system any more): form posts, JSON answers, an auth_key
 * from POST /auth/ on every call. Field names from GoGetSSL's PHP SDK and its documented answers; not yet tried
 * against the real API.
 */
class GoGetSslClient
{
    public const URL = 'https://my.gogetssl.com/api';

    /** webserver_type "Other": the certificate comes in the usual PEM files. */
    private const WEBSERVER = '-1';

    private ?string $key = null;

    public function __construct(private string $username, private string $password) {}

    public static function fromConfig(): self
    {
        return new self((string) config('gaiacp.gogetssl.username'), (string) config('gaiacp.gogetssl.password'));
    }

    /** @return array{balance: string, currency: string} */
    public function balance(): array
    {
        $body = $this->get('/account/balance/', 'balance');

        return ['balance' => (string) ($body['balance'] ?? '0'), 'currency' => (string) ($body['currency'] ?? '')];
    }

    /** @return array<string, CertificateProduct> */
    public function products(): array
    {
        $products = [];

        foreach ((array) ($this->get('/products/ssl/', 'products')['products'] ?? []) as $item) {
            if (! is_array($item) || blank($item['id'] ?? null)) {
                continue;
            }

            $methods = array_values(array_filter(['dns', 'http', 'https', 'email'], fn (string $method) => self::yes($item["dcv_{$method}"] ?? true)));
            $periods = array_values(array_filter(array_map('intval', is_array($item['periods'] ?? null) ? $item['periods'] : explode(',', (string) ($item['periods'] ?? '12')))));

            $products[(string) $item['id']] = new CertificateProduct(
                code: (string) $item['id'],
                name: (string) ($item['name'] ?? $item['product'] ?? $item['id']),
                wildcard: self::yes($item['wildcard'] ?? false),
                organization: self::yes($item['organization'] ?? false),
                periods: $periods ?: [12],
                methods: $methods ?: ['dns', 'http', 'email'],
            );
        }

        return $products;
    }

    public function order(CertificateOrder $order, bool $renewal = false): CertificateState
    {
        $body = $this->post($renewal ? '/orders/add_ssl_renew_order/' : '/orders/add_ssl_order/', $renewal ? 'renew' : 'order', $this->orderFields($order) + [
            'product_id' => $order->productCode,
            'period' => $order->months,
            'server_count' => -1,
        ]);

        $id = (string) ($body['order_id'] ?? throw new ProvisioningException('GoGetSSL did not say which order it made.'));
        $validation = self::validation($body['approver_method'] ?? $body['validation'] ?? []);

        // The validation details may only come with the order's status.
        if ($validation === []) {
            return rescue(fn () => $this->status($id), new CertificateState($id, CertificateStatus::Pending, method: $order->method), report: false);
        }

        return new CertificateState($id, CertificateStatus::Pending, $validation, $order->method);
    }

    public function status(string $orderId): CertificateState
    {
        $body = $this->get('/orders/status/'.(int) $orderId, 'status');
        $certificate = self::pem($body['crt_code'] ?? null);

        $status = match (strtolower((string) ($body['status'] ?? ''))) {
            'active' => $certificate ? CertificateStatus::Issued : CertificateStatus::Pending,
            'cancelled', 'canceled' => CertificateStatus::Cancelled,
            'expired' => CertificateStatus::Expired,
            'rejected', 'revoked' => CertificateStatus::Rejected,
            default => CertificateStatus::Pending, // new_order, processing, pending, incomplete, unpaid, reissue
        };

        // While a reissue is processed the old certificate is still "active".
        if ((int) ($body['reissue_now'] ?? 0) === 1 && $status === CertificateStatus::Issued) {
            $status = CertificateStatus::Pending;
        }

        $method = strtolower((string) ($body['dcv_method'] ?? '')) ?: null;
        $validation = self::validation($body['approver_method'] ?? []);

        if ($method === 'email' && ! isset($validation['email']) && filled($email = $body['approver_email'] ?? null)) {
            $validation['email'] = ['email' => (string) $email];
        }

        return new CertificateState(
            orderId: (string) ($body['order_id'] ?? $orderId),
            status: $status,
            validation: $validation,
            method: in_array($method, ['dns', 'http', 'https', 'email'], true) ? $method : null,
            certificate: $certificate,
            caBundle: self::pem($body['ca_code'] ?? null),
            validFrom: self::date($body['valid_from'] ?? null),
            validTill: self::date($body['valid_till'] ?? null),
            endsAt: self::date($body['end_date'] ?? null),
            message: filled($body['status_description'] ?? null) ? (string) $body['status_description'] : null,
        );
    }

    public function reissue(string $orderId, CertificateOrder $order): CertificateState
    {
        $body = $this->post('/orders/ssl/reissue/'.(int) $orderId, 'reissue', $this->orderFields($order));
        $validation = self::validation($body['validation'] ?? $body['approver_method'] ?? []);

        return new CertificateState((string) ($body['order_id'] ?? $orderId), CertificateStatus::Pending, $validation, $order->method);
    }

    /** @return array<string, mixed> */
    public function changeValidation(string $orderId, string $domain, string $method, ?string $approverEmail): array
    {
        $body = $this->post('/orders/ssl/change_dcv/'.(int) $orderId, 'change_dcv', array_filter([
            'domain_name' => $domain,
            'new_method' => $method,
            'approver_email' => $method === 'email' ? $approverEmail : null,
        ]));

        $validation = self::validation($body);

        if ($method === 'email' && ! isset($validation['email'])) {
            $validation['email'] = ['email' => (string) ($body['approver_email'] ?? $approverEmail)];
        }

        return $validation;
    }

    public function recheck(string $orderId, string $domain, string $method): void
    {
        if ($method === 'email') {
            $this->get('/orders/ssl/resend_validation_email/'.(int) $orderId, 'resend_email');

            return;
        }

        $this->post('/orders/ssl/revalidate/'.(int) $orderId, 'revalidate', ['domain' => $domain]);
    }

    public function cancel(string $orderId, string $reason): void
    {
        $this->post('/orders/cancel_ssl_order/', 'cancel', ['order_id' => (int) $orderId, 'reason' => $reason]);
    }

    /** @return array<string, mixed> what an order and a reissue both send */
    private function orderFields(CertificateOrder $order): array
    {
        $contact = $order->contact;
        $person = [
            'firstname' => $contact['first_name'] ?? '',
            'lastname' => $contact['last_name'] ?? '',
            'email' => $contact['email'] ?? '',
            'phone' => self::phone($contact['phone'] ?? ''),
            'title' => 'Owner',
            'organization' => $contact['company'] ?? '',
            'addressline1' => $contact['address'] ?? '',
            'city' => $contact['city'] ?? '',
            'country' => $contact['country'] ?? '',
            'postalcode' => $contact['postcode'] ?? '',
            'region' => $contact['state'] ?? '',
        ];

        $fields = [
            'csr' => $order->csr,
            'dcv_method' => $order->method,
            'webserver_type' => self::WEBSERVER,
            'signature_hash' => 'SHA2',
        ];

        if ($order->method === 'email' && filled($order->approverEmail)) {
            $fields['approver_email'] = $order->approverEmail;
        }

        foreach (['admin', 'tech'] as $role) {
            foreach ($person as $field => $value) {
                $fields["{$role}_{$field}"] = $value;
            }
        }

        if ($order->organization) {
            $fields += [
                'org_name' => $contact['company'] ?: trim(($contact['first_name'] ?? '').' '.($contact['last_name'] ?? '')),
                'org_division' => 'IT',
                'org_addressline1' => $contact['address'] ?? '',
                'org_city' => $contact['city'] ?? '',
                'org_country' => $contact['country'] ?? '',
                'org_phone' => self::phone($contact['phone'] ?? ''),
                'org_postalcode' => $contact['postcode'] ?? '',
                'org_region' => ($contact['state'] ?? '') ?: ($contact['city'] ?? ''),
            ];
        }

        return array_filter($fields, fn ($value) => $value !== '');
    }

    /** @return array<string, mixed> */
    private function get(string $path, string $action): array
    {
        return $this->call('get', $path, $action);
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    private function post(string $path, string $action, array $fields): array
    {
        return $this->call('post', $path, $action, $fields);
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     *
     * @throws ProvisioningException
     */
    private function call(string $verb, string $path, string $action, array $fields = []): array
    {
        $key = $this->key();
        $url = self::URL.$path.'?auth_key='.rawurlencode($key);
        $request = Http::asForm()->acceptJson()->timeout(30)
            ->withMiddleware(ModuleLogger::middleware('gogetssl', $action, [$key, $this->password], ['crt_code', 'csr_code']));

        try {
            $response = $verb === 'get' ? $request->get($url) : $request->post($url, $fields);
        } catch (ConnectionException $e) {
            throw new ProvisioningException('Could not reach GoGetSSL: '.$e->getMessage(), previous: $e);
        }

        return self::body($response->json(), $response->status());
    }

    private function key(): string
    {
        if ($this->key !== null) {
            return $this->key;
        }

        if (blank($this->username) || blank($this->password)) {
            throw new ProvisioningException('Fill in the GoGetSSL API username and password under Settings → GoGetSSL.');
        }

        try {
            $response = Http::asForm()->acceptJson()->timeout(30)
                ->withMiddleware(ModuleLogger::middleware('gogetssl', 'auth', [$this->password], ['key']))
                ->post(self::URL.'/auth/', ['user' => $this->username, 'pass' => $this->password]);
        } catch (ConnectionException $e) {
            throw new ProvisioningException('Could not reach GoGetSSL: '.$e->getMessage(), previous: $e);
        }

        $body = self::body($response->json(), $response->status());

        return $this->key = filled($body['key'] ?? null) ? (string) $body['key'] : throw new ProvisioningException('GoGetSSL did not accept the API login. Check the username and password under Settings → GoGetSSL.');
    }

    /** @return array<string, mixed> the answer, or ProvisioningException with GoGetSSL's own message */
    private static function body(mixed $json, int $status): array
    {
        if (! is_array($json)) {
            throw new ProvisioningException("GoGetSSL answered with HTTP {$status} and no data.");
        }

        if (! empty($json['error']) || (isset($json['success']) && $json['success'] === false)) {
            $message = trim(implode(': ', array_filter([
                is_string($json['message'] ?? null) ? $json['message'] : null,
                is_string($json['description'] ?? null) ? $json['description'] : null,
            ])));

            throw new ProvisioningException('GoGetSSL: '.($message ?: (is_string($json['error']) ? $json['error'] : "error (HTTP {$status})")));
        }

        if ($status >= 400) {
            throw new ProvisioningException("GoGetSSL answered with HTTP {$status}.");
        }

        return $json;
    }

    /**
     * dns => [record], http / https => [link, filename, content], email => [email]
     *
     * @return array<string, mixed>
     */
    private static function validation(mixed $data): array
    {
        if (! is_array($data)) {
            return [];
        }

        $validation = [];

        if (filled($record = data_get($data, 'dns.record'))) {
            $validation['dns'] = ['record' => (string) $record];
        }

        foreach (['http', 'https'] as $method) {
            if (filled(data_get($data, "{$method}.link")) || filled(data_get($data, "{$method}.content"))) {
                $validation[$method] = [
                    'link' => (string) data_get($data, "{$method}.link", ''),
                    'filename' => (string) data_get($data, "{$method}.filename", ''),
                    'content' => (string) data_get($data, "{$method}.content", ''),
                ];
            }
        }

        if (filled($email = data_get($data, 'email.email') ?? (is_string($data['email'] ?? null) ? $data['email'] : null))) {
            $validation['email'] = ['email' => (string) $email];
        }

        return $validation;
    }

    private static function pem(mixed $text): ?string
    {
        $text = trim((string) $text);

        return str_contains($text, '-----BEGIN') ? str_replace("\r\n", "\n", $text)."\n" : null;
    }

    private static function date(mixed $value): ?CarbonImmutable
    {
        if (blank($value) || str_starts_with((string) $value, '0000')) {
            return null;
        }

        try {
            return CarbonImmutable::parse((string) $value)->startOfDay();
        } catch (Throwable) {
            return null;
        }
    }

    private static function yes(mixed $value): bool
    {
        return in_array(strtolower(trim((string) $value)), ['yes', 'true', '1'], true) || $value === true;
    }

    /** Digits only, with the + of an international number. */
    private static function phone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        return $digits === '' ? '' : (str_starts_with(trim($phone), '+') ? '+' : '').$digits;
    }
}
