<?php

namespace App\Support;

use App\Enums\ServiceType;
use App\Models\Product;

/**
 * Settings → Domains → SSL with domains: one SSL certificate product offered with a domain ordered on its own (the
 * domain search, the Order page, the public order page), for the same name, at domains.ssl_discount percent off its
 * first period; renewals are at its usual price. It is its own service in the same order and payment notice, on the
 * product's cycle, and set up as any SSL product is (by its certificate plugin, or by hand).
 */
class DomainSsl
{
    /** The product offered, while clients can order it with a price. */
    public static function product(): ?Product
    {
        $id = config('gaiacp.domains.ssl_product');

        if (blank($id)) {
            return null;
        }

        $product = Product::query()->clientOrderable()->where('type', ServiceType::Ssl)->find($id);

        return $product?->defaultCycle() !== null ? $product : null;
    }

    public static function discount(): int
    {
        return max(0, min(100, (int) config('gaiacp.domains.ssl_discount', 0)));
    }

    /**
     * The order item of the certificate for $domain: the product's default cycle, the discount off its first period.
     *
     * @return array<string, mixed>|null null when none is offered
     */
    public static function item(string $domain, string $currency): ?array
    {
        $product = self::product();
        $cycle = $product?->defaultCycle();

        if ($cycle === null) {
            return null;
        }

        $price = (int) $product->priceFor($cycle, $currency);
        $first = (int) round($price * (100 - self::discount()) / 100) + $product->setupFeeFor($cycle, $currency);

        return [
            'product_id' => $product->id,
            'type' => ServiceType::Ssl,
            'label' => $product->name,
            'domain' => $domain,
            'server_id' => null,
            'package' => $product->package,
            'billing_cycle' => $cycle,
            'options' => [],
            'price_cents' => $price,
            'first_payment_cents' => $first !== $price ? $first : null,
        ];
    }

    /** What the certificate adds to the first payment, in $currency; null when none is offered. */
    public static function firstPayment(string $currency): ?int
    {
        $item = self::item('example.com', $currency);

        return $item === null ? null : ($item['first_payment_cents'] ?? $item['price_cents']);
    }
}
