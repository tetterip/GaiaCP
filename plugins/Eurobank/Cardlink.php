<?php

namespace Plugins\Eurobank;

/**
 * The Cardlink (Modirum) VPOS redirect protocol that Eurobank uses (the same as Alpha Bank's): the fields of the payment
 * form and the digest that signs it and the bank's answer. digest = base64(sha256(the values in order . shared secret)).
 *
 * Ported from the WooCommerce plugin "Eurobank WooCommerce Payment Gateway" 2.1.1 by Papaki (Enartia S.A.),
 * GPL-3.0-or-later, as maintained in tetterip/woo-tet-eurobank-payment-gateway. Pure: no settings, no database.
 */
class Cardlink
{
    public const LIVE_URL = 'https://vpos.eurocommerce.gr/vpos/shophandlermpi';

    public const TEST_URL = 'https://eurocommerce-test.cardlink.gr/vpos/shophandlermpi';

    /** The bank settles in euro only. */
    public const CURRENCY = 'EUR';

    /** The answer's fields that are not signed. */
    private const UNSIGNED = ['_charset_', 'digest', 'submitButton'];

    /** The answer's fields in the order the original plugin signed them, for an answer that sends them in another order. */
    private const RESPONSE_FIELDS = ['version', 'mid', 'orderid', 'status', 'orderAmount', 'currency', 'paymentTotal', 'message', 'riskScore', 'payMethod', 'txId', 'paymentRef'];

    /** @param  array<string, scalar|null>  $fields  values in the order they are sent */
    public static function sign(array $fields, string $secret): string
    {
        $data = '';

        foreach ($fields as $value) {
            $data .= (string) $value;
        }

        $clean = @iconv('UTF-8', 'UTF-8//IGNORE', $data);

        return base64_encode(hash('sha256', ($clean === false ? $data : $clean).$secret, true));
    }

    /**
     * Whether the bank's answer is signed with the shared secret: every field it sent, in the order sent, or the
     * original plugin's fixed list. Both need the secret, so accepting either does not weaken the check.
     *
     * @param  array<string, string>  $post  the raw POST fields, in the order received
     */
    public static function verify(array $post, string $secret): bool
    {
        $received = $post['digest'] ?? '';

        if ($secret === '' || $received === '') {
            return false;
        }

        $signed = array_diff_key($post, array_flip(self::UNSIGNED));

        if (hash_equals(self::sign($signed, $secret), $received)) {
            return true;
        }

        $ordered = array_map(fn (string $field) => $post[$field] ?? '', array_combine(self::RESPONSE_FIELDS, self::RESPONSE_FIELDS));

        return hash_equals(self::sign($ordered, $secret), $received);
    }

    /**
     * A form-encoded body as fields in the order sent, values untouched (no trimming, which would break the digest).
     * A repeated name keeps its last value.
     *
     * @return array<string, string>
     */
    public static function parse(string $body): array
    {
        $fields = [];

        foreach (explode('&', $body) as $pair) {
            if ($pair === '') {
                continue;
            }

            [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $fields[urldecode($name)] = urldecode($value);
        }

        return $fields;
    }

    /** "60.00" for 6000 hundredths. */
    public static function amount(int $cents): string
    {
        return intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    /** The bank's "60", "60.0" or "60.00" in hundredths; null when it is not a plain amount. */
    public static function cents(string $amount): ?int
    {
        if (! preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', trim($amount), $m)) {
            return null;
        }

        return (int) $m[1] * 100 + (int) str_pad($m[2] ?? '0', 2, '0');
    }
}
