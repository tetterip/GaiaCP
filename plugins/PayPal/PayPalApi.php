<?php

namespace Plugins\PayPal;

use App\Support\ModuleLogger;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * The few calls of PayPal's REST API that payments need (Orders v2), with the app keys of the mode in use. Calls are in
 * the module log (paypal) with the keys and token masked. No PayPal SDK: JSON requests.
 */
class PayPalApi
{
    public const LIVE_URL = 'https://api-m.paypal.com';

    public const SANDBOX_URL = 'https://api-m.sandbox.paypal.com';

    /** "live" or "sandbox" (Settings → PayPal). */
    public static function mode(): string
    {
        return config('gaiacp.paypal.mode') === 'live' ? 'live' : 'sandbox';
    }

    public static function baseUrl(): string
    {
        return self::mode() === 'live' ? self::LIVE_URL : self::SANDBOX_URL;
    }

    public static function clientId(): ?string
    {
        $id = config('gaiacp.paypal.'.self::mode().'_client_id');

        return filled($id) ? (string) $id : null;
    }

    public static function secret(): ?string
    {
        $secret = config('gaiacp.paypal.'.self::mode().'_secret');

        return filled($secret) ? (string) $secret : null;
    }

    /** Whether the mode in use has its client ID and secret (no "Pay with PayPal" button until it has). */
    public static function configured(): bool
    {
        return self::clientId() !== null && self::secret() !== null;
    }

    /** Where PayPal sends its events: the app's Webhooks → Add Webhook. */
    public static function webhookUrl(): string
    {
        return route('webhooks.paypal');
    }

    /**
     * @param  array<string, mixed>  $order  PayPal's Create order body
     * @return array<string, mixed> the order, with its links
     */
    public function createOrder(array $order, string $requestId): array
    {
        return $this->send('create_order', fn (PendingRequest $http) => $http
            ->withHeaders(['PayPal-Request-Id' => $requestId])
            ->post(self::baseUrl().'/v2/checkout/orders', $order));
    }

    /** @return array<string, mixed> */
    public function order(string $id): array
    {
        return $this->send('get_order', fn (PendingRequest $http) => $http->get(self::baseUrl().'/v2/checkout/orders/'.rawurlencode($id)));
    }

    /**
     * Takes the money of an order the client approved. The same request id makes a repeated call (after a timeout)
     * return the first capture instead of taking the money twice.
     *
     * @return array<string, mixed> the order, with its capture
     */
    public function capture(string $id): array
    {
        return $this->send('capture_order', fn (PendingRequest $http) => $http
            ->withHeaders(['PayPal-Request-Id' => 'capture-'.$id])
            ->withBody('{}', 'application/json')
            ->post(self::baseUrl().'/v2/checkout/orders/'.rawurlencode($id).'/capture'));
    }

    /** Proves the keys work (Settings → PayPal → Check the keys): PayPal gives a token only for a valid app. */
    public function check(): void
    {
        Cache::forget($this->tokenCacheKey());
        $this->token();
    }

    /**
     * @param  callable(PendingRequest): Response  $call
     * @return array<string, mixed>
     */
    private function send(string $action, callable $call): array
    {
        $token = $this->token();

        return $this->json(fn () => $call(Http::timeout(30)->acceptJson()->asJson()
            ->withToken($token)
            ->withMiddleware(ModuleLogger::middleware('paypal', $action, [$token]))));
    }

    /** An OAuth access token for the app, kept until shortly before PayPal lets it expire. */
    private function token(): string
    {
        if (! self::configured()) {
            throw new PayPalException(__('The PayPal client ID or secret is missing (Settings → PayPal).'));
        }

        if (is_string($token = Cache::get($this->tokenCacheKey())) && $token !== '') {
            return $token;
        }

        $reply = $this->json(fn () => Http::timeout(30)->acceptJson()->asForm()
            ->withBasicAuth((string) self::clientId(), (string) self::secret())
            ->withMiddleware(ModuleLogger::middleware('paypal', 'token', [(string) self::secret(), base64_encode(self::clientId().':'.self::secret())], ['access_token']))
            ->post(self::baseUrl().'/v1/oauth2/token', ['grant_type' => 'client_credentials']));

        $token = (string) ($reply['access_token'] ?? '');

        if ($token === '') {
            throw new PayPalException(__('PayPal did not return an access token.'));
        }

        Cache::put($this->tokenCacheKey(), $token, max(60, (int) ($reply['expires_in'] ?? 3600) - 300));

        return $token;
    }

    /** One per mode and app, so a changed key never uses the old app's token. */
    private function tokenCacheKey(): string
    {
        return 'paypal.token.'.self::mode().'.'.hash('sha256', self::clientId().':'.self::secret());
    }

    /**
     * @param  callable(): Response  $call
     * @return array<string, mixed>
     */
    private function json(callable $call): array
    {
        try {
            $response = $call();
        } catch (ConnectionException) {
            throw new PayPalException(__('PayPal could not be reached. Please try again.'));
        }

        if (! $response->successful()) {
            $error = $response->json('details.0.description') ?? $response->json('message') ?? $response->json('error_description') ?? 'HTTP '.$response->status();

            throw new PayPalException(__('PayPal refused the request: :error', ['error' => $error]));
        }

        return (array) $response->json();
    }
}
