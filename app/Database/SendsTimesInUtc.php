<?php

namespace App\Database;

use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * A database connection that stores times in UTC: a date or time given to a query (now(), a Carbon) is converted to
 * UTC before it is sent, so `where('created_at', '>', now()->subDay())` and `insert(['sent_at' => now()])` agree with
 * what Eloquent stores (App\Models\Concerns\StoresTimesInUtc). A plain date column (due_date, next_due_date) is
 * compared and written as a date string ('2026-11-06', `toDateString()`, whereOnDate()), never as a Carbon, or it
 * would move to the previous day east of UTC; the tests fail on one (Tests\Concerns\GuardsDateColumns).
 */
trait SendsTimesInUtc
{
    public function prepareBindings(array $bindings)
    {
        foreach ($bindings as $key => $value) {
            if ($value instanceof DateTimeInterface) {
                $bindings[$key] = Carbon::instance($value)->utc();
            }
        }

        return parent::prepareBindings($bindings);
    }
}
