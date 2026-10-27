<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

/**
 * What a domain search found (App\Support\DomainSearch): Available, Taken, or Unknown when neither the registrar nor
 * RDAP answered and the domain has no nameservers either, so it is probably free; the order checks it again.
 */
enum DomainAvailability: string implements HasColor, HasLabel
{
    case Available = 'available';
    case Taken = 'taken';
    case Unknown = 'unknown';

    /** Whether it can be put in the cart to register. */
    public function orderable(): bool
    {
        return $this !== self::Taken;
    }

    /**
     * What the order forms show by the domain typed (null: nothing): whether it is free to register, or, for a
     * transfer, that it isn't registered yet.
     */
    public function orderHint(bool $transfer): ?string
    {
        return match (true) {
            ! $transfer => $this->getLabel(),
            $this === self::Available => __('Not registered yet: register it instead'),
            default => null,
        };
    }

    public function orderHintIcon(bool $transfer): Heroicon
    {
        return match (true) {
            $transfer, $this === self::Unknown => Heroicon::OutlinedExclamationTriangle,
            $this === self::Available => Heroicon::OutlinedCheckCircle,
            default => Heroicon::OutlinedXCircle,
        };
    }

    public function orderHintColor(bool $transfer): string
    {
        return $transfer ? 'warning' : $this->getColor();
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Available => __('Available'),
            self::Taken => __('Taken'),
            self::Unknown => __('Probably available'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Available => 'success',
            self::Taken => 'danger',
            self::Unknown => 'warning',
        };
    }
}
