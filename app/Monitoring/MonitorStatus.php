<?php

namespace App\Monitoring;

/** A server's state as its monitoring service reports it (servers.monitor_status). */
enum MonitorStatus: string
{
    case Up = 'up';
    case Down = 'down';
    case Pending = 'pending';
    case Maintenance = 'maintenance';
    /** The monitor is paused, gone, or the service could not tell. */
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Up => __('Up'),
            self::Down => __('Down'),
            self::Pending => __('Pending'),
            self::Maintenance => __('Maintenance'),
            self::Unknown => __('Unknown'),
        };
    }

    /** Filament colour. */
    public function color(): string
    {
        return match ($this) {
            self::Up => 'success',
            self::Down => 'danger',
            self::Pending, self::Maintenance => 'warning',
            self::Unknown => 'gray',
        };
    }
}
