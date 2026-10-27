<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/** A line of a client's credit ledger (credit_transactions). */
enum CreditType: string implements HasColor, HasLabel
{
    /** Paid in: an "Add funds" payment notice was paid. */
    case Deposit = 'deposit';

    /** Paid out: a payment notice line was paid from the balance. */
    case Applied = 'applied';

    /** Paid back in: a payment made from credit was reversed, or failed to record. */
    case Refund = 'refund';

    /** Added or taken off by an admin. */
    case Adjustment = 'adjustment';

    public function getLabel(): string
    {
        return __(match ($this) {
            self::Deposit => 'Funds added',
            self::Applied => 'Used for a payment',
            self::Refund => 'Returned to credit',
            self::Adjustment => 'Adjustment',
        });
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Deposit, self::Refund => 'success',
            self::Applied => 'gray',
            self::Adjustment => 'warning',
        };
    }
}
