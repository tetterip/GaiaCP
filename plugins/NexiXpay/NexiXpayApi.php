<?php

namespace Plugins\NexiXpay;

use App\Support\ModuleLogger;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * The few calls of the Nexi Payment Gateway API (NPG, "XPay") that payments need, with the API key of the mode in use.
 * Calls are in the module log (nexi-xpay) with the key and the security token masked. Paths, headers and fields as in
 * Nexi's WooCommerce plugin (WC_Gateway_NPG_API, Nexi XPay 8.4.0, GPL-3.0).
 */
class NexiXpayApi
{
    public const LIVE_URL = 'https://xpay.nexigroup.com';

    public const TEST_URL = 'https://xpaysandbox.nexigroup.com';

    public const PATH = '/api/phoenix-0.0/psp/api/v1/';

    /** "live" or "test" (Settings → Nexi XPay). */
    public static function mode(): string
    {
        return config('gaiacp.nexi_xpay.mode') === 'live' ? 'live' : 'test';
    }

    public static function baseUrl(): string
    {
        return (self::mode() === 'live' ? self::LIVE_URL : self::TEST_URL).self::PATH;
    }

    public static function apiKey(): ?string
    {
        $key = config('gaiacp.nexi_xpay.'.self::mode().'_api_key');

        return filled($key) ? trim((string) $key) : null;
    }

    /** Whether the mode in use has its API key (no button until it has). */
    public static function configured(): bool
    {
        return self::apiKey() !== null;
    }

    /** Where Nexi tells GaiaCP that a payment changed (sent with each payment, nothing to set up at Nexi). */
    public static function notificationUrl(): string
    {
        return route('webhooks.nexi-xpay');
    }

    /**
     * Opens a payment on Nexi's hosted page (POST orders/hpp).
     *
     * @param  array<string, mixed>  $body
     * @return array{hostedPage?: string, securityToken?: string}
     */
    public function hostedPage(array $body): array
    {
        return $this->send('create_order', fn (PendingRequest $http) => $http->post(self::baseUrl().'orders/hpp', $body), ['securityToken']);
    }

    /**
     * An order with its operations (GET orders/{orderId}); null when Nexi does not know it (yet).
     *
     * @return array<string, mixed>|null
     */
    public function order(string $orderId): ?array
    {
        return $this->send('get_order', fn (PendingRequest $http) => $http->get(self::baseUrl().'orders/'.rawurlencode($orderId)), allowMissing: true);
    }

    /** Proves the key works (Settings → Nexi XPay → Check the key): Nexi lists the payment methods only for a valid key. */
    public function check(): void
    {
        $this->send('payment_methods', fn (PendingRequest $http) => $http->get(self::baseUrl().'payment_methods'));
    }

    /**
     * @param  callable(PendingRequest): Response  $call
     * @param  list<string>  $secretResponseKeys
     * @return ($allowMissing is true ? array<string, mixed>|null : array<string, mixed>)
     */
    private function send(string $action, callable $call, array $secretResponseKeys = [], bool $allowMissing = false): ?array
    {
        $key = self::apiKey();

        if ($key === null) {
            throw new NexiXpayException(__('The Nexi XPay API key is missing (Settings → Nexi XPay).'));
        }

        try {
            $response = $call(Http::timeout(30)->acceptJson()->asJson()
                ->withHeaders(['X-Api-Key' => $key, 'Correlation-Id' => (string) Str::uuid()])
                ->withMiddleware(ModuleLogger::middleware('nexi-xpay', $action, [$key], $secretResponseKeys)));
        } catch (ConnectionException) {
            throw new NexiXpayException(__('Nexi could not be reached. Please try again.'));
        }

        if ($allowMissing && $response->status() === 404) {
            return null;
        }

        if (! $response->successful()) {
            $error = $response->json('errors.0.description') ?? $response->json('errors.0.code') ?? 'HTTP '.$response->status();

            throw new NexiXpayException(__('Nexi refused the request: :error', ['error' => $error]));
        }

        return (array) $response->json();
    }
}
