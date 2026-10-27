<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ServiceStatus: string implements HasColor, HasLabel
{
    case Pending = 'pending';
    case Active = 'active';
    case Suspended = 'suspended';
    case Terminated = 'terminated';
    case Cancelled = 'cancelled';
    // A one-time service once paid and set up: nothing more to bill (Service::completeIfPaidOnce()).
    case Completed = 'completed';

    public function getLabel(): string
    {
        return __(ucfirst($this->value));
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'gray',
            self::Active => 'success',
            self::Suspended => 'warning',
            self::Terminated, self::Cancelled => 'danger',
            self::Completed => 'info',
        };
    }

    /**
     * Statuses that are still billed and get renewal reminders.
     *
     * @return list<self>
     */
    public static function billable(): array
    {
        return [self::Active, self::Suspended];
    }
}
