<?php

namespace Plugins\Webhooks;

use App\Events\GaiaEvent;
use App\Models\ActivityLog;
use App\Support\ModuleLogger;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Queues each event for the endpoints that want it and sends what is due (gaiacp:send-webhooks, every minute).
 *
 * A request is a POST of the delivery's JSON with X-GaiaCP-Event, X-GaiaCP-Delivery (the same on every try, for the
 * receiver to drop repeats), X-GaiaCP-Timestamp and X-GaiaCP-Signature: "sha256=" + the hex HMAC-SHA256 of
 * "{timestamp}.{body}" with the endpoint's secret. Any 2xx answer is a success; anything else is tried again after
 * gaiacp.webhooks.retry_minutes, then the delivery has failed. Failed tries in a row are counted on the endpoint: the
 * bell says so once, and too many switch it off.
 */
class WebhookSender
{
    public const TEST_EVENT = 'webhook.test';

    /** Longest answer kept with a delivery, in characters. */
    private const MAX_RESPONSE = 2000;

    /** @return int how many deliveries were queued */
    public function queue(GaiaEvent $event): int
    {
        $endpoints = WebhookEndpoint::query()->active()->get()->filter(fn (WebhookEndpoint $endpoint) => $endpoint->wants($event->name));

        if ($endpoints->isEmpty()) {
            return 0;
        }

        $body = self::encode(['uuid' => null] + $event->payload());

        foreach ($endpoints as $endpoint) {
            $this->create($endpoint, $event->name, $body);
        }

        return $endpoints->count();
    }

    /** @return array{sent: int, failed: int} the tries made now */
    public function sendDue(): array
    {
        $summary = ['sent' => 0, 'failed' => 0];

        $due = WebhookDelivery::query()
            ->with('endpoint')
            ->where('status', DeliveryStatus::Pending)
            ->where('next_attempt_at', '<=', now())
            ->orderBy('next_attempt_at')
            ->orderBy('id')
            ->limit(max(1, (int) config('gaiacp.webhooks.per_run', 100)))
            ->get();

        foreach ($due as $delivery) {
            // Switched off meanwhile (by hand or for failing): what waited for it is not sent
            if (! $delivery->endpoint->active) {
                $delivery->update(['status' => DeliveryStatus::Failed, 'next_attempt_at' => null, 'error' => 'The endpoint is switched off']);

                continue;
            }

            $this->attempt($delivery) ? $summary['sent']++ : $summary['failed']++;
        }

        return $summary;
    }

    /** Sends a sample event to the endpoint now, once (no retries, not counted as a failure). */
    public function test(WebhookEndpoint $endpoint): WebhookDelivery
    {
        $delivery = $this->create($endpoint, self::TEST_EVENT, self::encode([
            'uuid' => null,
            'event' => self::TEST_EVENT,
            'id' => null,
            'occurred_at' => now()->toIso8601String(),
            'description' => 'Test event from GaiaCP',
            'client' => null,
            'service' => null,
            'properties' => null,
        ]));

        $this->attempt($delivery, test: true);

        return $delivery->refresh();
    }

    /** Tries a delivery again now, with all its retries back (Resend in the history). */
    public function resend(WebhookDelivery $delivery): WebhookDelivery
    {
        $delivery->update(['status' => DeliveryStatus::Pending, 'attempts' => 0, 'next_attempt_at' => now()]);
        $this->attempt($delivery, test: $delivery->event === self::TEST_EVENT);

        return $delivery->refresh();
    }

    /** One try; true when the endpoint answered 2xx. */
    public function attempt(WebhookDelivery $delivery, bool $test = false): bool
    {
        $endpoint = $delivery->endpoint;
        $timestamp = (string) now()->getTimestamp();
        $body = $delivery->payload;
        $started = hrtime(true);
        $code = null;
        $response = null;
        $error = null;

        try {
            $address = WebhookAddress::inspect($endpoint->url);
            if ($address['error'] !== null) {
                throw new ConnectionException($address['error']);
            }

            $answer = Http::timeout(max(1, (int) config('gaiacp.webhooks.timeout', 10)))
                ->connectTimeout(5)
                ->withoutRedirecting()
                ->withOptions(self::pinned($endpoint->url, $address['ips']))
                ->withUserAgent('GaiaCP-Webhooks/1.0')
                ->withHeaders([
                    'X-GaiaCP-Event' => $delivery->event,
                    'X-GaiaCP-Delivery' => $delivery->uuid,
                    'X-GaiaCP-Timestamp' => $timestamp,
                    'X-GaiaCP-Signature' => 'sha256='.self::signature($timestamp, $body, (string) $endpoint->secret),
                ])
                ->withMiddleware(ModuleLogger::middleware('webhooks', $delivery->event, [$endpoint->secret]))
                ->withBody($body, 'application/json')
                ->post($endpoint->url);

            $code = $answer->status();
            $response = Str::limit($answer->body(), self::MAX_RESPONSE);
            $error = $answer->successful() ? null : "HTTP {$code}";
        } catch (ConnectionException $e) {
            $error = Str::limit($e->getMessage(), 480);
        } catch (Throwable $e) {
            report($e);
            $error = Str::limit($e->getMessage(), 480);
        }

        $attempts = $delivery->attempts + 1;
        $retries = array_values((array) config('gaiacp.webhooks.retry_minutes', []));
        $success = $error === null;
        $retry = ! $success && ! $test && $attempts <= count($retries);

        $delivery->update([
            'status' => $success ? DeliveryStatus::Sent : ($retry ? DeliveryStatus::Pending : DeliveryStatus::Failed),
            'attempts' => $attempts,
            'next_attempt_at' => $retry ? now()->addMinutes((int) $retries[$attempts - 1]) : null,
            'response_code' => $code,
            'response_body' => $response,
            'error' => $error,
            'duration_ms' => (int) round((hrtime(true) - $started) / 1e6),
            'delivered_at' => $success ? now() : null,
        ]);

        if ($success) {
            $endpoint->update(['failures' => 0, 'alerted_at' => null, 'last_success_at' => now()]);
        } elseif (! $test) {
            $this->failed($endpoint, $error);
        }

        return $success;
    }

    /**
     * Connects to the address WebhookAddress checked, not to a new DNS answer.
     *
     * @param  list<string>  $ips
     * @return array<string, mixed>
     */
    private static function pinned(string $url, array $ips): array
    {
        $host = (string) parse_url($url, PHP_URL_HOST);
        if ($ips === [] || filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) || ! defined('CURLOPT_RESOLVE')) {
            return [];
        }

        $port = parse_url($url, PHP_URL_PORT) ?: (str_starts_with(strtolower($url), 'https') ? 443 : 80);
        $ip = str_contains($ips[0], ':') ? "[{$ips[0]}]" : $ips[0];

        return ['curl' => [CURLOPT_RESOLVE => ["{$host}:{$port}:{$ip}"]]];
    }

    public static function signature(string $timestamp, string $body, string $secret): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }

    private function create(WebhookEndpoint $endpoint, string $event, string $body): WebhookDelivery
    {
        $uuid = (string) Str::uuid();

        return WebhookDelivery::query()->create([
            'uuid' => $uuid,
            'webhook_endpoint_id' => $endpoint->id,
            'event' => $event,
            // The delivery's own id goes into the JSON too, so a receiver that keeps only the body can still drop repeats
            'payload' => preg_replace('/^\{"uuid":null/', '{"uuid":"'.$uuid.'"', $body, 1),
            'status' => DeliveryStatus::Pending,
            'next_attempt_at' => now(),
        ])->setRelation('endpoint', $endpoint);
    }

    /** Counts a failed try on the endpoint: the bell once at alert_after, switched off at disable_after. */
    private function failed(WebhookEndpoint $endpoint, string $error): void
    {
        $endpoint->increment('failures', 1, ['last_failure_at' => now()]);
        $properties = ['endpoint_id' => $endpoint->id, 'name' => $endpoint->name, 'failures' => $endpoint->failures, 'error' => $error, 'url' => WebhooksPage::getUrl(panel: 'admin')];

        if ($endpoint->failures >= max(1, (int) config('gaiacp.webhooks.disable_after', 50))) {
            $endpoint->update(['active' => false]);
            $endpoint->deliveries()->where('status', DeliveryStatus::Pending)
                ->update(['status' => DeliveryStatus::Failed, 'next_attempt_at' => null, 'error' => 'The endpoint is switched off']);
            ActivityLog::record('webhook.disabled', "Webhook {$endpoint->name} switched off after {$endpoint->failures} failed tries in a row: {$error}", properties: $properties);

            return;
        }

        if ($endpoint->alerted_at === null && $endpoint->failures >= max(1, (int) config('gaiacp.webhooks.alert_after', 5))) {
            $endpoint->update(['alerted_at' => now()]);
            ActivityLog::record('webhook.failing', "Webhook {$endpoint->name} failed {$endpoint->failures} times in a row: {$error}", properties: $properties);
        }
    }

    /** @param array<string, mixed> $data */
    private static function encode(array $data): string
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
