<?php

namespace Plugins\Stripe;

/**
 * Checks the Stripe-Signature header of a webhook call: an HMAC-SHA256 of "timestamp.body" with the endpoint's signing
 * secret, made no more than TOLERANCE seconds ago (as Stripe's own libraries check it).
 */
class WebhookSignature
{
    public const TOLERANCE = 300;

    /** @param list<string> $secrets */
    public static function valid(string $payload, ?string $header, array $secrets, ?int $now = null): bool
    {
        if (blank($header) || $secrets === []) {
            return false;
        }

        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $header) as $part) {
            [$name, $value] = array_pad(explode('=', trim($part), 2), 2, '');

            if ($name === 't') {
                $timestamp = ctype_digit($value) ? (int) $value : null;
            } elseif ($name === 'v1') {
                $signatures[] = $value;
            }
        }

        if ($timestamp === null || $signatures === [] || abs(($now ?? time()) - $timestamp) > self::TOLERANCE) {
            return false;
        }

        foreach ($secrets as $secret) {
            $expected = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);

            foreach ($signatures as $signature) {
                if (hash_equals($expected, $signature)) {
                    return true;
                }
            }
        }

        return false;
    }
}
