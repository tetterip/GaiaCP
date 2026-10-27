<?php

namespace App\Models;

use App\Support\Currencies;
use App\Support\DomainName;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A TLD and its selling prices (Settings → Domain pricing): register / transfer / renew in cents for each term in
 * YEARS (null = that term is not offered), the late renewal fees (the grace fee once the domain has expired, the
 * redemption fee too after grace_days) and the preferred registrar, which handles its domains unless a domain
 * names its own. Prices are in the base currency; currency_prices ({USD: {column: cents}}) holds them in other
 * currencies, and a missing one is the base price at that currency's rate. costs ({register_1y_cents: cents}) is what
 * the registrar charges for each of those, in the base currency (missing = not known), for margins and the reports.
 */
#[Fillable([
    'tld', 'registrar_id', 'active', 'epp_required',
    'register_1y_cents', 'register_2y_cents', 'register_3y_cents',
    'transfer_1y_cents', 'transfer_2y_cents', 'transfer_3y_cents',
    'renew_1y_cents', 'renew_2y_cents', 'renew_3y_cents',
    'grace_days', 'grace_fee_cents', 'redemption_fee_cents', 'currency_prices', 'costs',
])]
class Tld extends Model
{
    /** The terms a domain can be priced for: one per billing cycle of whole years (BillingCycle::forYears()). */
    public const YEARS = [1, 2, 3];

    public const KINDS = ['register', 'transfer', 'renew'];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'epp_required' => 'boolean',
            ...array_fill_keys(self::priceColumns(), 'integer'),
            'grace_days' => 'integer',
            'grace_fee_cents' => 'integer',
            'redemption_fee_cents' => 'integer',
            'currency_prices' => 'array',
            'costs' => 'array',
        ];
    }

    /** @return list<string> register_1y_cents … renew_3y_cents */
    public static function priceColumns(): array
    {
        return collect(self::KINDS)->crossJoin(self::YEARS)->map(fn (array $pair) => "{$pair[0]}_{$pair[1]}y_cents")->all();
    }

    /** "1 year", "2 years"… */
    public static function termLabel(int $years): string
    {
        return $years === 1 ? __('1 year') : __(':count years', ['count' => $years]);
    }

    /** Stored as ".com.gr": lowercase with one leading dot. */
    protected function tld(): Attribute
    {
        return Attribute::set(fn (string $value) => '.'.ltrim(mb_strtolower(trim($value)), '.'));
    }

    /** @param  Builder<Tld>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('active', true);
    }

    /**
     * The TLD row of a domain, the longest match ("x.com.gr" → ".com.gr"). $activeOnly leaves out TLDs no longer
     * sold; renewals, fees and the registrar of a domain already here use the row either way.
     */
    public static function match(?string $domain, bool $activeOnly = true): ?self
    {
        $domain = DomainName::normalize($domain);

        if ($domain === '') {
            return null;
        }

        // An IDN TLD may be entered either way (".ελ" or ".xn--qxam").
        return self::query()->when($activeOnly, fn (Builder $q) => $q->active())->get()
            ->filter(fn (self $t) => strlen($domain) > strlen($t->tld)
                && (str_ends_with($domain, $t->tld) || str_ends_with(DomainName::toAscii($domain), DomainName::toAscii($t->tld))))
            ->sortByDesc(fn (self $t) => strlen($t->tld))
            ->first();
    }

    /** @return list<string> every price column: the terms' and the late fees' */
    public static function moneyColumns(): array
    {
        return [...self::priceColumns(), 'grace_fee_cents', 'redemption_fee_cents'];
    }

    /** Selling price for "register", "transfer" or "renew" over $years, in $currency (none = base); null when not offered. */
    public function priceCents(string $kind, int $years, ?string $currency = null): ?int
    {
        return in_array($years, self::YEARS, true) ? $this->inCurrency("{$kind}_{$years}y_cents", $currency) : null;
    }

    /** A price column in $currency: the one set for it, else the base one at its rate; null when the base has none. */
    public function inCurrency(string $column, ?string $currency): ?int
    {
        $base = $this->{$column};
        $currency = strtoupper((string) $currency);

        if ($base === null || $currency === '' || $currency === Currencies::base()) {
            return $base;
        }

        $set = $this->currency_prices[$currency][$column] ?? null;

        return $set !== null && $set !== '' ? (int) $set : Currencies::fromBase((int) $base, $currency);
    }

    /** Update product prices: every other currency's prices set to the base ones at today's rates. */
    public function convertPrices(): void
    {
        $this->currency_prices = collect(Currencies::others())->mapWithKeys(fn (string $code) => [$code => collect(self::moneyColumns())
            ->mapWithKeys(fn (string $column) => [$column => $this->{$column} === null ? null : Currencies::fromBase((int) $this->{$column}, $code)])
            ->all(),
        ])->all() ?: null;
        $this->save();
    }

    /** What the registrar charges for "register", "transfer" or "renew" over $years, in the base currency; null when not known. */
    public function costCents(string $kind, int $years): ?int
    {
        $cost = $this->costs["{$kind}_{$years}y_cents"] ?? null;

        return $cost === null || $cost === '' ? null : (int) $cost;
    }

    /** Selling price minus cost for a term, in the base currency; null when either is missing. */
    public function marginCents(string $kind, int $years): ?int
    {
        $price = $this->priceCents($kind, $years);
        $cost = $this->costCents($kind, $years);

        return $price === null || $cost === null ? null : $price - $cost;
    }

    /** Whether some term sells for less than it costs. */
    public function sellsAtALoss(): bool
    {
        foreach (self::KINDS as $kind) {
            foreach (self::YEARS as $years) {
                if (($this->marginCents($kind, $years) ?? 0) < 0) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @return list<int> the terms with a $kind price */
    public function terms(string $kind): array
    {
        return array_values(array_filter(self::YEARS, fn (int $years) => $this->priceCents($kind, $years) !== null));
    }

    /**
     * The late renewal fees owed on $today for a domain that expired on $expires.
     *
     * @return array<'grace'|'redemption', int> cents in $currency (none = base)
     */
    public function lateFees(CarbonInterface $expires, CarbonInterface $today, ?string $currency = null): array
    {
        if (! $today->greaterThan($expires)) {
            return [];
        }

        $fees = array_filter(['grace' => (int) $this->inCurrency('grace_fee_cents', $currency)]);

        if ($this->grace_days !== null && $today->greaterThan($expires->copy()->addDays($this->grace_days)) && $this->redemption_fee_cents) {
            $fees['redemption'] = (int) $this->inCurrency('redemption_fee_cents', $currency);
        }

        return $fees;
    }

    /** @return BelongsTo<Registrar, $this> the preferred registrar */
    public function registrar(): BelongsTo
    {
        return $this->belongsTo(Registrar::class);
    }
}
