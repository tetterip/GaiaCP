<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Where a lead (a prospective client, App\Actions\Leads) stands: New, Contacted, Quote sent, then Won (became a
 * client) or Lost (with its reason). Won and Lost are closed: no more follow-ups.
 */
enum LeadStatus: string implements HasColor, HasLabel
{
    case New = 'new';
    case Contacted = 'contacted';
    case Quoted = 'quoted';
    case Won = 'won';
    case Lost = 'lost';

    /** @return list<self> the ones still being worked on */
    public static function open(): array
    {
        return [self::New, self::Contacted, self::Quoted];
    }

    public function isOpen(): bool
    {
        return in_array($this, self::open(), true);
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::New => __('New'),
            self::Contacted => __('Contacted'),
            self::Quoted => __('Quote sent'),
            self::Won => __('Won'),
            self::Lost => __('Lost'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::New => 'info',
            self::Contacted => 'warning',
            self::Quoted => 'primary',
            self::Won => 'success',
            self::Lost => 'gray',
        };
    }
}
