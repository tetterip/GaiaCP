<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** When a client asks a service to end (App\Actions\CancellationRequests). */
enum CancellationType: string implements HasLabel
{
    case Immediate = 'immediate';
    case EndOfPeriod = 'end_of_period';

    public function getLabel(): string
    {
        return match ($this) {
            self::Immediate => __('Immediately'),
            self::EndOfPeriod => __('At the end of the billing period'),
        };
    }
}
