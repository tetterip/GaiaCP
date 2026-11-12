<?php

namespace Plugins\MastercardGateway;

use App\Support\ModuleLogger;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * The few calls of Mastercard Gateway's REST API (MPGS, API version 100) that payments need, as the merchant of the
 * mode in use (Basic auth "merchant.<ID>" with the API password). Calls are in the module log (mastercard) with the
 * password masked. Hosts, paths and fields as in Fingent's WooCommerce plugin (Mastercard Gateway 1.5.4.1, Apache-2.0).
 */
class MastercardApi
{
    public const VERSION = '100';

    /** The gateways by region (Settings → Mastercard Gateway); a bank with its own address uses custom. */
    public const HOSTS = [
        'eu' => 'eu-gateway.mastercard.com',
        'ap' => 'ap-gateway.mastercard.com',
        'na' => 'na-gateway.mastercard.com',
    ];

    /** The name clients see on the button (Settings → Mastercard Gateway: the bank's name, say); "Mastercard" when empty. */
    public static function displayName(): string
    {
        $name = trim((string) config('gaiacp.mastercard.display_name'));

        return $name !== '' ? $name : 'Mastercard';
    }

    /** "live" or "test". */
    public static function mode(): string
    {
        return config('gaiacp.mastercard.mode') === 'live' ? 'live' : 'test';
    }

    /** The gateway's host name, without https:// or a path; null when a custom one is chosen but not filled in. */
    public static function host(): ?string
    {
        $region = (string) config('gaiacp.mastercard.host', 'eu');

        if ($region !== 'custom') {
            return self::HOSTS[$region] ?? self::HOSTS['eu'];
        }

        $host = (string) preg_replace('#^https?://#i', '', trim((string) config('gaiacp.mastercard.custom_host')));
        $host = strtolower(explode('/', $host)[0]);

        return preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $host) ? $host : null;
    }

    public static function merchantId(): ?string
    {
        $id = trim((string) config('gaiacp.mastercard.'.self::mode().'_merchant_id'));

        return $id !== '' ? $id : null;
    }

    public static function password(): ?string
    {
        $password = config('gaiacp.mastercard.'.self::mode().'_api_password');

        return filled($password) ? (string) $password : null;
    }

    /** Whether the gateway, merchant ID and API password of the mode in use are filled in (no button until they are). */
    public static function configured(): bool
    {
        return self::host() !== null && self::merchantId() !== null && self::password() !== null;
    }

    /** The gateway's script that opens its payment page (Checkout.showPaymentPage()). */
    public static function checkoutScript(): string
    {
        return 'https://'.self::host().'/static/checkout/checkout.min.js';
    }

    /**
     * Opens a hosted checkout session (INITIATE_CHECKOUT).
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed> with session.id and successIndicator
     */
    public function initiateCheckout(array $body): array
    {
        return $this->send('initiate_checkout', fn (PendingRequest $http) => $http->post($this->url('session'), ['apiOperation' => 'INITIATE_CHECKOUT'] + $body), ['successIndicator']);
    }

    /**
     * An order with its transactions; null when the gateway does not know it (the client has not paid yet).
     *
     * @return array<string, mixed>|null
     */
    public function order(string $orderId): ?array
    {
        return $this->send('retrieve_order', fn (PendingRequest $http) => $http->get($this->url('order/'.rawurlencode($orderId))), allowMissing: true);
    }

    /** Proves the details work (Settings → Mastercard Gateway → Check the details): only a valid merchant gets an answer. */
    public function check(): void
    {
        $this->send('payment_options', fn (PendingRequest $http) => $http->post($this->url('paymentOptionsInquiry'), (object) []));
    }

    private function url(string $path): string
    {
        return 'https://'.self::host().'/api/rest/version/'.self::VERSION.'/merchant/'.rawurlencode((string) self::merchantId()).'/'.$path;
    }

    /**
     * @param  callable(PendingRequest): Response  $call
     * @param  list<string>  $secretResponseKeys
     * @return ($allowMissing is true ? array<string, mixed>|null : array<string, mixed>)
     */
    private function send(string $action, callable $call, array $secretResponseKeys = [], bool $allowMissing = false): ?array
    {
        if (! self::configured()) {
            throw new MastercardException(__('The Mastercard Gateway details are missing (Settings → Mastercard Gateway).'));
        }

        $user = 'merchant.'.self::merchantId();
        $password = (string) self::password();

        try {
            $response = $call(Http::timeout(30)->acceptJson()->asJson()
                ->withBasicAuth($user, $password)
                ->withMiddleware(ModuleLogger::middleware('mastercard', $action, [$password, base64_encode($user.':'.$password)], $secretResponseKeys)));
        } catch (ConnectionException) {
            throw new MastercardException(__('The card gateway could not be reached. Please try again.'));
        }

        $json = (array) $response->json();

        // An order nobody paid yet: a 404, or a 400 whose explanation says the order is not found.
        if ($allowMissing && ($response->status() === 404 || ($response->status() === 400
            && preg_match('/not found|no order|unable to find|does not exist/i', (string) data_get($json, 'error.explanation'))))) {
            return null;
        }

        if (! $response->successful() || ($json['result'] ?? null) === 'ERROR') {
            $error = $response->json('error.explanation') ?? $response->json('error.cause') ?? 'HTTP '.$response->status();

            throw new MastercardException(__('The card gateway refused the request: :error', ['error' => $error]));
        }

        return $json;
    }
}
