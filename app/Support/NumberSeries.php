<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Numbers like PF-2026-0001 (payment notices) and QT-2026-0001 (quotes), one series a year. The last number given is
 * kept in `invoice_series` (as for tax invoices, App\Invoicing\InvoiceNumbers) and taken under a row lock, so two
 * notices made at the same moment (two checkouts, a checkout during the daily run) never get the same number. A
 * series starts from the highest number already used that year, compared as a number (PF-2026-10000 comes after
 * PF-2026-9999), and a number already in use is skipped. Called outside a transaction, the number is reserved at once;
 * one not saved after all leaves a gap, which these numbers may have.
 */
class NumberSeries
{
    /** @param  class-string<Model>  $model  the model whose `number` column holds these numbers */
    public static function next(string $model, string $prefix, int $year): string
    {
        $start = $prefix.'-'.$year.'-';
        $key = strtolower($prefix).':'.$year;

        return DB::transaction(function () use ($model, $start, $key) {
            if (! DB::table('invoice_series')->where('key', $key)->exists()) {
                DB::table('invoice_series')->insertOrIgnore([
                    'key' => $key,
                    'last_number' => self::highest($model, $start),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $last = (int) DB::table('invoice_series')->where('key', $key)->lockForUpdate()->value('last_number');

            do {
                $number = $start.str_pad((string) ++$last, 4, '0', STR_PAD_LEFT);
            } while ($model::query()->where('number', $number)->exists());

            DB::table('invoice_series')->where('key', $key)->update(['last_number' => $last, 'updated_at' => now()]);

            return $number;
        });
    }

    /** @param  class-string<Model>  $model */
    private static function highest(string $model, string $start): int
    {
        return (int) $model::query()->where('number', 'like', $start.'%')->pluck('number')
            ->map(fn (string $number) => substr($number, strlen($start)))
            ->filter(fn (string $suffix) => ctype_digit($suffix))
            ->map(fn (string $suffix) => (int) $suffix)
            ->max();
    }
}
