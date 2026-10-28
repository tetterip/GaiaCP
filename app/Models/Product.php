<?php

namespace App\Models;

use App\Enums\AutoSetup;
use App\Enums\BillingCycle;
use App\Enums\ServiceStatus;
use App\Enums\ServiceType;
use App\Support\Currencies;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A catalog entry that pre-fills new services. Each billing cycle has its own price
 * and setup fee (prices: {cycle: {enabled, price_cents, setup_cents}}); billing_cycle is
 * the cycle picked by default. Hidden products stay out of the new order picker;
 * retired ones out of every picker, while their existing services keep them. client_visible ("Show in client area")
 * is copied to its new services and keeps it out of what clients can order; Maintenance is never client-orderable.
 *
 * An add-on (addon) is sold only on top of a service of the products it fits (addonFor; none = any), never on its own
 * in New order or the client area: App\Actions\AddAddon.
 *
 * The order page lists it in its group (product_group_id, Settings → Product groups) with its icon (App\Support\ProductIcons)
 * and its `features` ({feature key: value}), the values of its group's compare table; `popular` adds a badge there, and
 * so do its visible tags (Settings → Product tags, badges()).
 *
 * Prices are in the base currency; currency_prices ({USD: {cycle: {price_cents, setup_cents}}}) holds them in the
 * other currencies of Settings → Currencies, and a missing one is the base price at that currency's rate.
 *
 * @property array<string, array{enabled?: bool, price_cents?: int|null, setup_cents?: int|null}>|null $prices
 * @property array<string, array<string, array{price_cents?: int|null, setup_cents?: int|null}>>|null $currency_prices
 */
#[Fillable(['name', 'type', 'product_group_id', 'addon', 'description', 'icon', 'features', 'popular', 'prices', 'currency_prices', 'currency', 'billing_cycle', 'package', 'hidden', 'retired', 'client_visible', 'auto_setup'])]
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(function (Product $product) {
            if (! array_key_exists('client_visible', $product->getAttributes())) {
                $product->client_visible = true;
            }
        });
    }

    protected function casts(): array
    {
        return [
            'type' => ServiceType::class,
            'addon' => 'boolean',
            'billing_cycle' => BillingCycle::class,
            'prices' => 'array',
            'currency_prices' => 'array',
            'features' => 'array',
            'popular' => 'boolean',
            'hidden' => 'boolean',
            'retired' => 'boolean',
            'client_visible' => 'boolean',
            'auto_setup' => AutoSetup::class,
        ];
    }

    /** @return BelongsTo<ProductGroup, $this> where the order page lists it (Settings → Product groups) */
    public function group(): BelongsTo
    {
        return $this->belongsTo(ProductGroup::class, 'product_group_id');
    }

    /** At most this many tags show as badges on a card, so it stays readable on a phone. */
    public const BADGES = 3;

    /** @return BelongsToMany<ProductTag, $this> Settings → Product tags */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(ProductTag::class)->orderBy('product_tags.sort')->orderBy('product_tags.id');
    }

    /** @return list<array{name: string, color: string}> its visible tags, the badges clients see after Popular */
    public function badges(): array
    {
        return $this->tags
            ->where('visible', true)
            ->take(self::BADGES)
            ->map(fn (ProductTag $tag) => ['name' => $tag->name(), 'color' => $tag->hex()])
            ->values()->all();
    }

    /** Its value for one of its group's features (a row of the compare table), or null. */
    public function feature(string $key): ?string
    {
        $value = $this->features[$key] ?? null;

        return filled($value) ? (string) $value : null;
    }

    /** @return BelongsToMany<Server, $this> The servers a hosting product can go on, each with the same package. */
    public function servers(): BelongsToMany
    {
        return $this->belongsToMany(Server::class);
    }

    /**
     * The server a new account of this product goes on: the active one of its servers that is least full (accounts,
     * pending ones included, against Maximum accounts; no maximum counts as empty, a tie goes to fewer accounts, then
     * the name). Full servers are skipped, so null when every one is full or the product has none.
     */
    public function pickServer(): ?Server
    {
        return $this->servers()
            ->where('active', true)
            ->withAccountsCount()
            ->withCount(['services as pending_accounts_count' => fn (Builder $query) => $query
                ->where('type', ServiceType::Hosting)->where('status', ServiceStatus::Pending)])
            ->get()
            ->map(fn (Server $server) => [$server, $server->accountsUsed() + (int) $server->pending_accounts_count])
            ->reject(fn (array $pair) => $pair[0]->max_accounts && $pair[1] >= $pair[0]->max_accounts)
            ->sortBy([
                fn (array $a, array $b) => ($a[0]->max_accounts ? $a[1] / $a[0]->max_accounts : 0) <=> ($b[0]->max_accounts ? $b[1] / $b[0]->max_accounts : 0),
                fn (array $a, array $b) => $a[1] <=> $b[1],
                fn (array $a, array $b) => $a[0]->name <=> $b[0]->name,
            ])
            ->first()[0] ?? null;
    }

    /** @return BelongsToMany<ProductOptionGroup, $this> Settings → Product options offered with it. */
    public function optionGroups(): BelongsToMany
    {
        return $this->belongsToMany(ProductOptionGroup::class);
    }

    /** @return BelongsToMany<Product, $this> for an add-on: the products it can be added to */
    public function addonFor(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_addon', 'addon_id', 'product_id');
    }

    /** @return BelongsToMany<Product, $this> the add-ons that name this product */
    public function addons(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_addon', 'product_id', 'addon_id');
    }

    /**
     * @param  Builder<Product>  $query  add-ons that can go on a service of $product: those naming it, and those naming
     *                                   no product at all
     */
    public function scopeAddonsFor(Builder $query, ?Product $product): void
    {
        $query->where('addon', true)->where('retired', false)->where(fn (Builder $q) => $q
            ->whereDoesntHave('addonFor')
            ->when($product, fn (Builder $q) => $q->orWhereHas('addonFor', fn (Builder $q) => $q->whereKey($product->id))));
    }

    /** @return HasMany<Service, $this> */
    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    /** @param  Builder<Product>  $query  Offered in admin pickers (New service). */
    public function scopeSelectable(Builder $query): void
    {
        $query->where('retired', false);
    }

    /** @param  Builder<Product>  $query  Offered in the new order picker (add-ons only go on a service). */
    public function scopeOrderable(Builder $query): void
    {
        $query->where('retired', false)->where('hidden', false)->where('addon', false);
    }

    /** @param  Builder<Product>  $query  Clients may order it in the client area: shown there and of a type clients order. */
    public function scopeClientOrderable(Builder $query): void
    {
        $query->orderable()->where('client_visible', true)
            ->whereIn('type', array_filter(ServiceType::cases(), fn (ServiceType $type) => $type->clientOrderable()));
    }

    /** @return list<BillingCycle> the enabled cycles, shortest first */
    public function cycles(): array
    {
        // One time only for other services (BillingCycle::optionsFor())
        $offered = BillingCycle::optionsFor($this->type);

        return array_values(array_filter(BillingCycle::cases(), fn (BillingCycle $cycle) => isset($offered[$cycle->value]) && $this->priceFor($cycle) !== null));
    }

    /** The default cycle when it is enabled, else the first enabled one. */
    public function defaultCycle(): ?BillingCycle
    {
        return $this->billing_cycle && $this->priceFor($this->billing_cycle) !== null ? $this->billing_cycle : ($this->cycles()[0] ?? null);
    }

    /** Price per cycle in cents, in $currency (none = the base one); null when the cycle is not offered. */
    public function priceFor(BillingCycle $cycle, ?string $currency = null): ?int
    {
        $price = $this->prices[$cycle->value] ?? null;

        if (! ($price['enabled'] ?? false) || ! isset($price['price_cents'])) {
            return null;
        }

        return $this->inCurrency($cycle, 'price_cents', (int) $price['price_cents'], $currency);
    }

    public function setupFeeFor(BillingCycle $cycle, ?string $currency = null): int
    {
        return $this->priceFor($cycle) === null ? 0 : $this->inCurrency($cycle, 'setup_cents', (int) ($this->prices[$cycle->value]['setup_cents'] ?? 0), $currency);
    }

    /** The price set in $currency, else the base one at its rate. */
    private function inCurrency(BillingCycle $cycle, string $field, int $base, ?string $currency): int
    {
        $currency = strtoupper((string) $currency);

        if ($currency === '' || $currency === Currencies::base()) {
            return $base;
        }

        $set = $this->currency_prices[$currency][$cycle->value][$field] ?? null;

        return $set !== null && $set !== '' ? (int) $set : Currencies::fromBase($base, $currency);
    }

    /** Update product prices: every other currency's prices set to the base ones at today's rates. */
    public function convertPrices(): void
    {
        $this->currency_prices = collect(Currencies::others())->mapWithKeys(fn (string $code) => [$code => collect($this->prices ?? [])
            ->map(fn (array $price) => [
                'price_cents' => isset($price['price_cents']) ? Currencies::fromBase((int) $price['price_cents'], $code) : null,
                'setup_cents' => isset($price['setup_cents']) ? Currencies::fromBase((int) $price['setup_cents'], $code) : null,
            ])->all(),
        ])->all() ?: null;
        $this->save();
    }
}
