<?php

namespace App\Models;

use App\Enums\CouponType;
use App\Enums\OrderStatus;
use App\Enums\ServiceType;
use App\Support\Currencies;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A coupon (Billing → Coupons): a code clients enter in the cart, or an admin picks on New order, that lowers the
 * price of what it covers by a percentage or by a fixed amount (in the base currency, converted for the client's)
 * off each item. Without `recurring` it lowers only the first payment; with it the service keeps the lower price.
 * A setup fee is never lowered. It covers the products in `product_ids` and the domains ending in `tlds`, or
 * everything when both are empty. An item on sale (its product's or TLD's sale price) only with `on_sale`, so two
 * discounts are never given by accident. Used = placed on an order that was not cancelled (App\Support\Coupons).
 *
 * @property list<int>|null $product_ids
 * @property list<string>|null $tlds
 * @property CarbonImmutable|null $starts_on
 * @property CarbonImmutable|null $ends_on
 */
#[Fillable(['code', 'description', 'type', 'value', 'recurring', 'product_ids', 'tlds', 'starts_on', 'ends_on', 'max_uses', 'once_per_client', 'new_clients_only', 'active', 'on_sale'])]
class Coupon extends Model
{
    protected $attributes = [
        'type' => 'percent',
        'active' => true,
    ];

    protected function casts(): array
    {
        return [
            'type' => CouponType::class,
            'value' => 'integer',
            'recurring' => 'boolean',
            'product_ids' => 'array',
            'tlds' => 'array',
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
            'max_uses' => 'integer',
            'once_per_client' => 'boolean',
            'new_clients_only' => 'boolean',
            'active' => 'boolean',
            'on_sale' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $coupon) {
            $coupon->code = self::normalize($coupon->code);
            $coupon->product_ids = collect($coupon->product_ids ?? [])->filter()->map(fn ($id) => (int) $id)->unique()->values()->all() ?: null;
            $coupon->tlds = collect($coupon->tlds ?? [])->filter()->map(fn ($tld) => Str::lower((string) $tld))->unique()->values()->all() ?: null;
        });
    }

    /** Codes are matched without case or spaces around them. */
    public static function normalize(?string $code): string
    {
        return Str::upper(trim((string) $code));
    }

    /** @return HasMany<Order, $this> */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /** @return HasMany<Order, $this> the orders it was used on: those not cancelled */
    public function uses(): HasMany
    {
        return $this->orders()->where('status', '!=', OrderStatus::Cancelled);
    }

    /** @param  Builder<self>  $query */
    public function scopeCurrent(Builder $query, ?CarbonImmutable $today = null): void
    {
        $today = ($today ?? CarbonImmutable::today())->toDateString();

        $query->where('active', true)
            ->where(fn (Builder $q) => $q->whereNull('starts_on')->orWhereDate('starts_on', '<=', $today))
            ->where(fn (Builder $q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', $today));
    }

    /** Whether it covers everything (no products or domains chosen). */
    public function coversAll(): bool
    {
        return empty($this->product_ids) && empty($this->tlds);
    }

    /**
     * Whether it covers an order item: a domain by its ending, anything else by its product.
     *
     * @param  array<string, mixed>  $item  a PlaceOrder item
     */
    public function covers(array $item): bool
    {
        if (isset($item['sale_cents']) && ! $this->on_sale) {
            return false;
        }

        if ($this->coversAll()) {
            return true;
        }

        $type = $item['type'] instanceof ServiceType ? $item['type'] : ServiceType::tryFrom((string) $item['type']);

        if ($type === ServiceType::Domain) {
            $tld = Tld::match($item['domain'] ?? null, activeOnly: false)?->tld;

            return $tld !== null && in_array(Str::lower($tld), $this->tlds ?? [], true);
        }

        return filled($item['product_id'] ?? null) && in_array((int) $item['product_id'], $this->product_ids ?? [], true);
    }

    /** How much it takes off a price in a currency: never more than the price. */
    public function discountOn(int $cents, string $currency): int
    {
        if ($cents <= 0) {
            return 0;
        }

        $off = $this->type === CouponType::Percent
            ? (int) round($cents * min(100, $this->value) / 100)
            : Currencies::fromBase($this->value, $currency);

        return min($cents, max(0, $off));
    }

    /** "10%" or "€5.00", what it takes off, in a currency. */
    public function describeValue(?string $currency = null): string
    {
        return $this->type === CouponType::Percent
            ? $this->value.'%'
            : Money::format(Currencies::fromBase($this->value, $currency ?? Currencies::base()), $currency ?? Currencies::base());
    }
}
