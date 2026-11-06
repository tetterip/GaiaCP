<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * Times are stored in UTC (App\Models\Concerns\StoresTimesInUtc, App\Database\SendsTimesInUtc). Eloquent models give
 * them back in local time already; a time read some other way (DB::table(), pluck() after toBase(), min() / max())
 * is the stored UTC text, which parse() turns into local time.
 */
class StoredTime
{
    /** A stored time ('2026-11-06 12:00:00', UTC) in the app's timezone, or null for none. */
    public static function parse(mixed $value, string $format = 'Y-m-d H:i:s'): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value)->setTimezone(config('app.timezone'));
        }

        try {
            $time = CarbonImmutable::createFromFormat($format, (string) $value, 'UTC');
        } catch (InvalidArgumentException) {
            $time = null;
        }

        return ($time ?: CarbonImmutable::parse((string) $value, 'UTC'))->setTimezone(config('app.timezone'));
    }
}
