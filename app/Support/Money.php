<?php

namespace App\Support;

use App\Enums\CurrencyFormat;
use Illuminate\Support\Number;
use InvalidArgumentException;

/**
 * Amounts are stored as integer cents. This converts at the UI/mail edges only.
 */
final class Money
{
    /**
     * "€12.50" in the reader's language, or as the currency's format says (Settings → Currencies: prefix, number,
     * suffix). No currency = the base one.
     */
    public static function format(int $cents, ?string $currency = null): string
    {
        $currency = strtoupper($currency ?: Currencies::base());
        $settings = Currencies::get($currency);

        if (! $settings || $settings->format === CurrencyFormat::Locale) {
            return (string) Number::currency($cents / 100, in: $currency, locale: app()->getLocale());
        }

        $amount = $settings->prefix.$settings->format->number(abs($cents) / 100).(filled($settings->suffix) ? "\u{00A0}".$settings->suffix : '');

        return ($cents < 0 ? '-' : '').$amount;
    }

    /** "12.50", "12,50" or 12.5 -> 1250. */
    public static function toCents(string|int|float $amount): int
    {
        $normalized = str_replace([' ', ','], ['', '.'], trim((string) $amount));

        if (! preg_match('/^\d+(\.\d{1,2})?$/', $normalized)) {
            throw new InvalidArgumentException("Invalid amount: {$amount}");
        }

        [$whole, $fraction] = array_pad(explode('.', $normalized), 2, '');

        return (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
    }

    /** 1250 -> "12.50", for form fields. */
    public static function toDecimal(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}
