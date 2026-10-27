<?php

namespace App\Support;

use App\Models\Client;
use App\Models\Currency;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Throwable;

/**
 * The currencies of Settings → Currencies, read once per request. The base currency is gaiacp.currency (EUR): every
 * other currency's rate says how many of it one base unit buys, prices in it default to the base price at that
 * rate, and totals over several currencies (reports, the dashboard) are converted to the base one.
 */
final class Currencies
{
    /** @var Collection<string, Currency>|null */
    private ?Collection $all = null;

    public static function base(): string
    {
        return strtoupper((string) (config('gaiacp.currency') ?: 'EUR'));
    }

    /** @return Collection<string, Currency> by code, the base one first */
    public static function all(): Collection
    {
        $self = app(self::class);

        if ($self->all === null) {
            try {
                $self->all = Currency::query()->orderBy('code')->get()->keyBy('code')
                    ->sortBy(fn (Currency $c) => $c->code === self::base() ? 0 : 1);
            } catch (Throwable) {
                return collect(); // not installed / migrated yet
            }
        }

        return $self->all;
    }

    public static function flush(): void
    {
        app(self::class)->all = null;
    }

    public static function get(?string $code): ?Currency
    {
        return self::all()->get(strtoupper((string) ($code ?: self::base())));
    }

    /** @return array<string, string> code => code */
    public static function options(): array
    {
        $codes = self::all()->keys()->all() ?: [self::base()];

        return array_combine($codes, $codes);
    }

    /** More than the base currency, so currency pickers and prices per currency show. */
    public static function multiple(): bool
    {
        return self::all()->count() > 1;
    }

    /** @return list<string> every currency but the base one */
    public static function others(): array
    {
        return self::all()->keys()->reject(fn (string $code) => $code === self::base())->values()->all();
    }

    /** A client's currency: its own while it is still on the list, else the base one. */
    public static function forClient(?Client $client): string
    {
        $code = strtoupper((string) $client?->currency);

        return $code !== '' && self::all()->has($code) ? $code : self::base();
    }

    /** How many of $code one base unit buys (1 for the base or an unknown currency). */
    public static function rate(?string $code): float
    {
        $rate = (float) (self::get($code)?->rate ?? 1);

        return $rate > 0 ? $rate : 1.0;
    }

    /** A base amount in $code at today's rate. */
    public static function fromBase(int $cents, ?string $code): int
    {
        return (int) round($cents * self::rate($code));
    }

    /** An amount in $code in the base currency at today's rate. */
    public static function toBase(int $cents, ?string $code): int
    {
        return (int) round($cents / self::rate($code));
    }

    /**
     * The sum of $column over every currency in $query, in the base currency.
     *
     * @param  Builder|EloquentBuilder<*>  $query
     */
    public static function sumToBase(Builder|EloquentBuilder $query, string $column, string $currencyColumn = 'currency'): int
    {
        $query = clone $query;
        $base = $query instanceof EloquentBuilder ? $query->toBase() : $query;
        $base->orders = null;
        $base->columns = null;

        return (int) $base->selectRaw("{$currencyColumn} as sum_currency, sum({$column}) as sum_cents")
            ->groupBy($currencyColumn)
            ->get()
            ->sum(fn ($row) => self::toBase((int) $row->sum_cents, $row->sum_currency));
    }
}
