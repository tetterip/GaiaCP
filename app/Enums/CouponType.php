<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** How a coupon lowers a price: by a percentage, or by a fixed amount off each item it covers. */
enum CouponType: string implements HasLabel
{
    case Percent = 'percent';
    case Fixed = 'fixed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Percent => __('Percentage'),
            self::Fixed => __('Fixed amount per item'),
        };
    }
}
