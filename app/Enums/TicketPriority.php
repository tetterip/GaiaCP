<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum TicketPriority: string implements HasColor, HasLabel
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';

    // Chosen by a client for a service that stopped working, a few times a month (Settings → Support → Urgent tickets)
    case Urgent = 'urgent';

    public function getLabel(): string
    {
        return match ($this) {
            self::Low => __('Low'),
            self::Medium => __('Medium'),
            self::High => __('High'),
            self::Urgent => __('Urgent'),
        };
    }

    /** @return array<string, string> the priorities a client picks from (Urgent has its own switch) */
    public static function clientOptions(): array
    {
        return collect([self::Low, self::Medium, self::High])->mapWithKeys(fn (self $p) => [$p->value => $p->getLabel()])->all();
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Low => 'gray',
            self::Medium => 'info',
            self::High => 'warning',
            self::Urgent => 'danger',
        };
    }
}
