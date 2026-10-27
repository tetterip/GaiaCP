<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * The built-in ways to record a payment by hand. The others (bank transfer, later card payments) come from payment
 * plugins: App\Payments\PaymentMethods lists them all.
 */
enum PaymentMethod: string implements HasLabel
{
    case Cash = 'cash';
    case Other = 'other';

    public function getLabel(): string
    {
        return __(match ($this) {
            self::Cash => 'Cash',
            self::Other => 'Other',
        });
    }
}
