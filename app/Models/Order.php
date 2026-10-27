<?php

namespace App\Models;

use App\Enums\OrderStatus;
use App\Support\Tax;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Services a client ordered together. The services are the items: each keeps its
 * own price, cycle and status, and is created pending until the order is completed.
 *
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $terms_accepted_at placed in the client area accepting the terms of service (TermsOfService), with terms_version
 * @property int|null $discount_cents what its coupon took off the first payment (set by PlaceOrder, with coupon_id)
 */
#[Fillable(['client_id', 'status', 'notes', 'completed_at'])]
class Order extends Model
{
    protected $attributes = [
        'status' => 'pending',
    ];

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'completed_at' => 'immutable_datetime',
            'terms_accepted_at' => 'immutable_datetime',
            'discount_cents' => 'integer',
        ];
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return BelongsTo<Coupon, $this> */
    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    /** @return HasMany<Service, $this> */
    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    /** @return HasMany<Invoice, $this> */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /** What the first payment of every item comes to. */
    public function totalCents(): int
    {
        return $this->services->sum(fn (Service $service) => $service->firstPaymentCents());
    }

    /**
     * The first payment with tax: what its payment notice asks for, or, before there is one, what the client's tax
     * rule makes of the items' first payments.
     *
     * @return array{net: int, tax: int, total: int, rule: array{name: string, rate: float}|null}
     */
    public function totals(): array
    {
        $notice = $this->relationLoaded('invoices') ? $this->invoices->sortByDesc('id')->first() : $this->invoices()->latest('id')->first();

        if ($notice) {
            return [
                'net' => $notice->total_cents - $notice->tax_cents,
                'tax' => $notice->tax_cents,
                'total' => $notice->total_cents,
                'rule' => $notice->tax_rate ? ['name' => (string) $notice->tax_name, 'rate' => (float) $notice->tax_rate] : null,
            ];
        }

        return Tax::totals($this->client, $this->services->map(fn (Service $service) => $service->firstPaymentCents()));
    }

    /** "€100.00 + ΦΠΑ 24% €24.00 = €124.00 with tax", or the amount alone when no tax applies. */
    public function describeTotal(): string
    {
        return Tax::describe($this->totals(), $this->currency());
    }

    public function currency(): string
    {
        return $this->services->first()->currency ?? config('gaiacp.currency');
    }
}
