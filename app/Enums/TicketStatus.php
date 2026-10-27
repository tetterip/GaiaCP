<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Where a support ticket stands: Open (Awaiting reply) waits on an admin (new, or the client wrote last), Answered waits on the
 * client, On hold waits on something else, Closed is done (a reply from either side opens it again).
 */
enum TicketStatus: string implements HasColor, HasLabel
{
    case Open = 'open';
    case Answered = 'answered';
    case OnHold = 'on_hold';
    case Closed = 'closed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Open => __('Awaiting reply'),
            self::Answered => __('Answered'),
            self::OnHold => __('On hold'),
            self::Closed => __('Closed'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Open => 'warning',
            self::Answered => 'info',
            self::OnHold => 'gray',
            self::Closed => 'success',
        };
    }
}
