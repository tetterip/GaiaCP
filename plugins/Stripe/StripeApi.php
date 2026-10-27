<?php

namespace Plugins\Stripe;

use App\Support\ModuleLogger;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * The few calls of Stripe's API that card payments need (Checkout Sessions), over HTTPS with the secret key of the
 * mode in use. Calls are in the module log (stripe) with the key masked. No Stripe SDK: form-encoded requests.
 */
class StripeApi
{
    public const BASE_URL = 'https://api.stripe.com/v1';

    /** Pinned so a change of the account's default API version can't change the replies read here. */
    public const API_VERSION = '2025-03-31.basil';

    /** "live" or "test" (Settings → Stripe). */
    public static function mode(): string
    {
        return config('gaiacp.stripe.mode') === 'live' ? 'live' : 'test';
    }

    public static function secretKey(): ?string
    {
        $key = config('gaiacp.stripe.'.self::mode().'_secret_key');

        return filled($key) ? (string) $key : null;
    }

    /** @return list<string> the webhook signing secrets of both modes: an event signed with either is Stripe's */
    public static function webhookSecrets(): array
    {
        return array_values(array_filter([
            config('gaiacp.stripe.'.self::mode().'_webhook_secret'),
            config('gaiacp.stripe.'.(self::mode() === 'live' ? 'test' : 'live').'_webhook_secret'),
        ], 'filled'));
    }

    /** Whether the mode in use has its secret key (no "Pay by card" button until it has). */
    public static function configured(): bool
    {
        return self::secretKey() !== null;
    }

    /** Where Stripe sends its events: Developers → Webhooks → Add endpoint. */
    public static function webhookUrl(): string
    {
        return route('webhooks.stripe');
    }

    /**
     * @param  array<string, mixed>  $params  Stripe's Checkout Session parameters
     * @return array<string, mixed> the session, with its `url`
     */
    public function createCheckoutSession(array $params, ?string $idempotencyKey = null): array
    {
        return $this->send('create_checkout', fn (PendingRequest $http) => $http
            ->withHeaders(array_filter(['Idempotency-Key' => $idempotencyKey]))
            ->asForm()
            ->post(self::BASE_URL.'/checkout/sessions', $params));
    }

    /** @return array<string, mixed> */
    public function checkoutSession(string $id): array
    {
        return $this->send('get_checkout', fn (PendingRequest $http) => $http->get(self::BASE_URL.'/checkout/sessions/'.rawurlencode($id)));
    }

    /** A read-only call that proves the key works and may use Checkout (Settings → Stripe → Check the key). */
    public function check(): void
    {
        $this->send('check', fn (PendingRequest $http) => $http->get(self::BASE_URL.'/checkout/sessions', ['limit' => 1]));
    }

    /**
     * @param  callable(PendingRequest): Response  $call
     * @return array<string, mixed>
     */
    private function send(string $action, callable $call): array
    {
        $key = self::secretKey() ?? throw new StripeException(__('The Stripe secret key is missing (Settings → Stripe).'));

        $http = Http::timeout(20)->acceptJson()
            ->withToken($key)
            ->withHeaders(['Stripe-Version' => self::API_VERSION])
            ->withMiddleware(ModuleLogger::middleware('stripe', $action, [$key]));

        try {
            $response = $call($http);
        } catch (ConnectionException) {
            throw new StripeException(__('Stripe could not be reached. Please try again.'));
        }

        if (! $response->successful()) {
            throw new StripeException(__('Stripe refused the request: :error', [
                'error' => $response->json('error.message') ?: 'HTTP '.$response->status(),
            ]));
        }

        return (array) $response->json();
    }
}
