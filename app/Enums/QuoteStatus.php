<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Where a quote stands: Draft (only the admins see it), Sent (its link works), then Accepted (it became an order),
 * Declined, or Expired (its valid until date passed while it was sent).
 */
enum QuoteStatus: string implements HasColor, HasLabel
{
    case Draft = 'draft';
    case Sent = 'sent';
    case Accepted = 'accepted';
    case Declined = 'declined';
    case Expired = 'expired';

    /** Whether it can still be changed and accepted. */
    public function isOpen(): bool
    {
        return in_array($this, [self::Draft, self::Sent], true);
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Draft => __('Draft'),
            self::Sent => __('Sent'),
            self::Accepted => __('Accepted'),
            self::Declined => __('Declined'),
            self::Expired => __('Expired'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Sent => 'info',
            self::Accepted => 'success',
            self::Declined => 'danger',
            self::Expired => 'warning',
        };
    }
}
