<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * How a currency's amounts are written. Locale follows the reader's language (Laravel's Number::currency, the
 * currency's symbol where the language has one) and ignores the prefix and suffix; the others are fixed.
 */
enum CurrencyFormat: string implements HasLabel
{
    case Locale = 'locale';
    case Plain = '1234.56';
    case CommaThousands = '1,234.56';
    case DotThousands = '1.234,56';
    case PlainComma = '1234,56';

    public function getLabel(): string
    {
        return $this === self::Locale ? __('By language') : $this->value;
    }

    /** 1234.5 → "1.234,50" (Locale is formatted by Money itself). */
    public function number(float $amount): string
    {
        return match ($this) {
            self::Plain, self::Locale => number_format($amount, 2, '.', ''),
            self::CommaThousands => number_format($amount, 2, '.', ','),
            self::DotThousands => number_format($amount, 2, ',', '.'),
            self::PlainComma => number_format($amount, 2, ',', ''),
        };
    }
}
