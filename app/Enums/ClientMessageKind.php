<?php

namespace App\Enums;

use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasLabel;

/** Whether a message to clients respects their "Informational emails" choice (App\Models\ClientMessage). */
enum ClientMessageKind: string implements HasDescription, HasLabel
{
    case Info = 'info';
    case Service = 'service';

    public function getLabel(): string
    {
        return match ($this) {
            self::Info => __('Informational'),
            self::Service => __('About their services'),
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::Info => __('News and offers: ends with an unsubscribe link and skips clients who turned off informational emails.'),
            self::Service => __('Something they need to know, e.g. a move to another server: reaches every client it matches, with no unsubscribe link.'),
        };
    }
}
