<?php

namespace App\Support;

use App\Models\Client;
use App\Plugins\PluginManager;

/**
 * Tax on payment notices (Settings → Tax). Rules are {name, rate, country}; a rule for the client's
 * country beats one for every country (country null). Prices are entered without tax (exclusive,
 * tax added on top) or with it (inclusive, tax is the part of the price it already contains).
 */
class Tax
{
    /** @return array{name: string, rate: float}|null the rule for this client, null when no tax applies */
    public static function ruleFor(Client $client): ?array
    {
        $rule = self::baseRuleFor($client);

        return $rule && self::pluginExemption($client) === null ? $rule : null;
    }

    /**
     * Why a client who would pay tax pays none because a plugin says so (Plugin::taxExemption(), e.g. the VIES
     * plugin's reverse charge), as the note printed on their payment notices; null when tax applies or there is none.
     */
    public static function exemptionFor(Client $client): ?string
    {
        return self::baseRuleFor($client) ? self::pluginExemption($client) : null;
    }

    private static function pluginExemption(Client $client): ?string
    {
        return app(PluginManager::class)->taxExemption($client);
    }

    /** @return array{name: string, rate: float}|null the Settings → Tax rule for this client, before any plugin */
    private static function baseRuleFor(Client $client): ?array
    {
        if (! config('gaiacp.tax.enabled') || $client->tax_exempt) {
            return null;
        }

        $rules = collect(config('gaiacp.tax.rules', []))
            ->filter(fn ($rule) => is_array($rule) && is_numeric($rule['rate'] ?? null) && (float) $rule['rate'] > 0);

        $rule = $rules->first(fn (array $rule) => ($rule['country'] ?? null) !== null && strtoupper($rule['country']) === strtoupper((string) $client->country))
            ?? $rules->first(fn (array $rule) => ($rule['country'] ?? null) === null);

        return $rule ? ['name' => (string) ($rule['name'] ?: 'Tax'), 'rate' => (float) $rule['rate']] : null;
    }

    public static function inclusive(): bool
    {
        return (bool) config('gaiacp.tax.inclusive');
    }

    /**
     * Tax and total of one price.
     *
     * @return array{tax: int, total: int}
     */
    public static function apply(int $amountCents, ?float $rate, bool $inclusive): array
    {
        if (! $rate) {
            return ['tax' => 0, 'total' => $amountCents];
        }

        if ($inclusive) {
            $net = (int) round($amountCents / (1 + $rate / 100));

            return ['tax' => $amountCents - $net, 'total' => $amountCents];
        }

        $tax = (int) round($amountCents * $rate / 100);

        return ['tax' => $tax, 'total' => $amountCents + $tax];
    }

    /**
     * A client's amounts with tax, line by line as their payment notice adds them up, so an order shows what its
     * notice will ask for.
     *
     * @param  iterable<int>  $amounts
     * @return array{net: int, tax: int, total: int, rule: array{name: string, rate: float}|null}
     */
    public static function totals(?Client $client, iterable $amounts): array
    {
        $rule = $client ? self::ruleFor($client) : null;
        $tax = $total = 0;

        foreach ($amounts as $amount) {
            $line = self::apply($amount, $rule['rate'] ?? null, self::inclusive());
            $tax += $line['tax'];
            $total += $line['total'];
        }

        return ['net' => $total - $tax, 'tax' => $tax, 'total' => $total, 'rule' => $rule];
    }

    /**
     * "€100.00 + ΦΠΑ 24% €24.00 = €124.00 with tax", or the amount alone when no tax applies.
     *
     * @param  array{net: int, tax: int, total: int, rule: array{name: string, rate: float}|null}  $totals
     */
    public static function describe(array $totals, string $currency): string
    {
        if (! $totals['rule']) {
            return Money::format($totals['total'], $currency);
        }

        return __(':net + :tax :tax_amount = :total with tax', [
            'net' => Money::format($totals['net'], $currency),
            'tax' => $totals['rule']['name'].' '.self::formatRate($totals['rule']['rate']),
            'tax_amount' => Money::format($totals['tax'], $currency),
            'total' => Money::format($totals['total'], $currency),
        ]);
    }

    /** "17%" or "17.5%". */
    public static function formatRate(float $rate): string
    {
        return rtrim(rtrim(number_format($rate, 3, '.', ''), '0'), '.').'%';
    }
}
