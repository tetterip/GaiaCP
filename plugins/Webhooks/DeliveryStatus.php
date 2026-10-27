<?php

namespace Plugins\Webhooks;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/** Where an event sent to an endpoint stands. */
enum DeliveryStatus: string implements HasColor, HasLabel
{
    // Not sent yet, or failed and waiting for its next try
    case Pending = 'pending';
    // The endpoint answered 2xx
    case Sent = 'sent';
    // Every try failed (or the endpoint was switched off); Resend tries again
    case Failed = 'failed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => __('Waiting'),
            self::Sent => __('Sent'),
            self::Failed => __('Failed'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Sent => 'success',
            self::Failed => 'danger',
        };
    }
}
