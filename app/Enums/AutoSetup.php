<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** When a pending service is set up (created on its panel, registered, or just activated), as in WHMCS. */
enum AutoSetup: string implements HasLabel
{
    case OnOrder = 'order';
    case OnPayment = 'payment';
    case OnAccept = 'accept';
    case Never = 'never';

    public function getLabel(): string
    {
        return __(match ($this) {
            self::OnOrder => 'Automatically set up as soon as an order is placed',
            self::OnPayment => 'Automatically set up as soon as the first payment is recorded',
            self::OnAccept => 'Automatically set up when you complete the order',
            self::Never => 'Do not set up automatically (use the Account or Registrar menu)',
        });
    }
}
