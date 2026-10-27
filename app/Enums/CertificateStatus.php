<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/** Where an SSL certificate stands at its provider (App\Models\Certificate). */
enum CertificateStatus: string implements HasColor, HasLabel
{
    /** Paid for but not ordered from the provider yet (its service is pending, or the order failed). */
    case NotOrdered = 'not_ordered';
    /** Ordered (or being reissued or renewed): waiting for the domain to be validated and the certificate issued. */
    case Pending = 'pending';
    case Issued = 'issued';
    case Expired = 'expired';
    case Cancelled = 'cancelled';
    /** Rejected or revoked by the certificate authority. */
    case Rejected = 'rejected';

    public function getLabel(): string
    {
        return __(match ($this) {
            self::NotOrdered => 'Not ordered yet',
            self::Pending => 'Waiting for validation',
            self::Issued => 'Issued',
            self::Expired => 'Expired',
            self::Cancelled => 'Cancelled',
            self::Rejected => 'Rejected',
        });
    }

    public function getColor(): string
    {
        return match ($this) {
            self::NotOrdered => 'gray',
            self::Pending => 'warning',
            self::Issued => 'success',
            self::Expired, self::Cancelled, self::Rejected => 'danger',
        };
    }
}
