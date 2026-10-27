<?php

namespace App\Support;

use App\Enums\OrderStatus;
use App\Enums\ServiceStatus;
use App\Enums\ServiceType;
use App\Models\Client;
use App\Models\Coupon;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Coupons on orders (App\Models\Coupon): whether a client can use one now, and the order items it lowers. Used by
 * PlaceOrder (inside its transaction, so a usage limit is never passed), the client area's cart (the code kept in
 * the session, also from a `?coupon=` link), admin New order and the API.
 */
class Coupons
{
    /** The session key of the code a client entered or followed a link with, until the order is placed. */
    public const SESSION = 'client_order.coupon';

    public static function find(?string $code): ?Coupon
    {
        $code = Coupon::normalize($code);

        return $code === '' ? null : Coupon::query()->where('code', $code)->first();
    }

    /**
     * Why a client can't use a coupon now, or null when they can.
     *
     * @param  list<array<string, mixed>>|null  $items  the order's items, to check it covers one of them
     */
    public static function problem(?Coupon $coupon, ?Client $client, ?array $items = null, ?CarbonImmutable $today = null): ?string
    {
        $today ??= CarbonImmutable::today();

        if ($coupon === null || ! $coupon->active) {
            return __('This coupon code is not valid.');
        }

        if (($coupon->starts_on && $coupon->starts_on->gt($today)) || ($coupon->ends_on && $coupon->ends_on->lt($today))) {
            return __('This coupon is not valid today.');
        }

        if ($coupon->max_uses !== null && $coupon->uses()->count() >= $coupon->max_uses) {
            return __('This coupon has been used up.');
        }

        if ($client !== null && $coupon->once_per_client && $coupon->uses()->where('client_id', $client->id)->exists()) {
            return __('You have already used this coupon.');
        }

        if ($client !== null && $coupon->new_clients_only && self::hasOrdered($client)) {
            return __('This coupon is for new clients only.');
        }

        if ($items !== null && ! collect($items)->contains(fn (array $item) => $coupon->covers($item))) {
            return __('This coupon does not cover anything in this order.');
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>|null  $items
     *
     * @throws ValidationException under `coupon`, with why it can't be used
     */
    public static function check(?Coupon $coupon, ?Client $client, ?array $items = null): void
    {
        if (($problem = self::problem($coupon, $client, $items)) !== null) {
            throw ValidationException::withMessages(['coupon' => $problem]);
        }
    }

    /**
     * The items with the coupon's discount: the first payment of each item it covers (its setup fee untouched),
     * and with `recurring` its price too. Each lowered item gets `coupon_id`.
     *
     * @param  list<array<string, mixed>>  $items  PlaceOrder items, prices in cents in $currency
     * @return array{items: list<array<string, mixed>>, discount_cents: int}
     */
    public static function apply(Coupon $coupon, array $items, string $currency): array
    {
        $discount = 0;

        foreach ($items as $index => $item) {
            if (! $coupon->covers($item)) {
                continue;
            }

            $price = (int) ($item['price_cents'] ?? 0);
            $first = isset($item['first_payment_cents']) ? (int) $item['first_payment_cents'] : $price;
            // A domain's first payment is its registration or transfer price; anything else's is its price plus its setup fee.
            $isDomain = ($item['type'] instanceof ServiceType ? $item['type'] : ServiceType::tryFrom((string) $item['type'])) === ServiceType::Domain;
            $off = $coupon->discountOn($isDomain ? $first : min($price, $first), $currency);

            if ($off === 0) {
                continue;
            }

            $item['first_payment_cents'] = $first - $off;

            if ($coupon->recurring) {
                $item['price_cents'] = $price - $coupon->discountOn($price, $currency);
            }

            if ($item['first_payment_cents'] === $item['price_cents']) {
                $item['first_payment_cents'] = null;
            }

            $item['coupon_id'] = $coupon->id;
            $items[$index] = $item;
            $discount += $off;
        }

        return ['items' => $items, 'discount_cents' => $discount];
    }

    /** The first payment the coupon saves on these items, for a total shown before the order is placed (0 when it can't be used). */
    public static function preview(?Coupon $coupon, ?Client $client, array $items, string $currency): int
    {
        if (self::problem($coupon, $client, $items) !== null) {
            return 0;
        }

        return self::apply($coupon, $items, $currency)['discount_cents'];
    }

    /** The code kept for the client area's cart, if any. */
    public static function remembered(): ?string
    {
        $code = session()->get(self::SESSION);

        return is_string($code) && $code !== '' ? $code : null;
    }

    public static function remember(?string $code): void
    {
        $code = Coupon::normalize($code);

        $code === '' ? session()->forget(self::SESSION) : session()->put(self::SESSION, mb_substr($code, 0, 50));
    }

    /** A `?coupon=CODE` link: kept for the cart. */
    public static function rememberFromLink(): void
    {
        if (is_string($code = request()->query('coupon')) && trim($code) !== '') {
            self::remember($code);
        }
    }

    private static function hasOrdered(Client $client): bool
    {
        return $client->orders()->where('status', '!=', OrderStatus::Cancelled)->exists()
            || $client->services()->whereNotIn('status', [ServiceStatus::Pending, ServiceStatus::Cancelled])->exists();
    }
}
