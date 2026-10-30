<?php

namespace App\Invoicing;

use App\Models\TaxInvoice;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Invoice numbers from Settings → Invoices → Number format: {YEAR} {MONTH} {DAY} and {NUMBER} (at least four digits).
 * Each series keeps the last number given (`invoice_series`), taken under a row lock inside the issuing transaction,
 * so numbers have no gaps and are never given twice. With {YEAR} in the format each year is a series of its own and
 * starts again at 1.
 */
class InvoiceNumbers
{
    public const DEFAULT_FORMAT = 'INV-{YEAR}-{NUMBER}';

    public const TAGS = ['{YEAR}', '{MONTH}', '{DAY}', '{NUMBER}'];

    public static function format(): string
    {
        $format = trim((string) config('gaiacp.invoicing.number_format'));

        return str_contains($format, '{NUMBER}') ? $format : self::DEFAULT_FORMAT;
    }

    /** The series an invoice dated $date is numbered in. */
    public static function series(CarbonInterface $date, ?string $format = null): string
    {
        return str_contains($format ?? self::format(), '{YEAR}') ? 'invoice:'.$date->year : 'invoice';
    }

    public static function build(int $number, CarbonInterface $date, ?string $format = null): string
    {
        return strtr($format ?? self::format(), [
            '{YEAR}' => $date->format('Y'),
            '{MONTH}' => $date->format('m'),
            '{DAY}' => $date->format('d'),
            '{NUMBER}' => str_pad((string) $number, 4, '0', STR_PAD_LEFT),
        ]);
    }

    /** The number the next invoice dated $date will get (1 in a series not used yet). */
    public static function upcoming(CarbonInterface $date): int
    {
        return (int) DB::table('invoice_series')->where('key', self::series($date))->value('last_number') + 1;
    }

    /**
     * Takes the next number: call inside the transaction that creates the invoice, so the lock is held until it is
     * saved. A number already used (the format was changed back to an older one) is skipped.
     */
    public static function take(CarbonInterface $date): string
    {
        $key = self::series($date);

        DB::table('invoice_series')->insertOrIgnore(['key' => $key, 'last_number' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $last = (int) DB::table('invoice_series')->where('key', $key)->lockForUpdate()->value('last_number');

        do {
            $number = self::build(++$last, $date);
        } while (TaxInvoice::query()->where('number', $number)->exists());

        DB::table('invoice_series')->where('key', $key)->update(['last_number' => $last, 'updated_at' => now()]);

        return $number;
    }

    /**
     * Settings → Invoices → Next number: the series for $date continues from $next. Only forward, so a number is never
     * given twice; false when $next is not above the last one given.
     */
    public static function continueFrom(int $next, CarbonInterface $date): bool
    {
        $key = self::series($date);

        return DB::transaction(function () use ($key, $next) {
            DB::table('invoice_series')->insertOrIgnore(['key' => $key, 'last_number' => 0, 'created_at' => now(), 'updated_at' => now()]);
            $last = (int) DB::table('invoice_series')->where('key', $key)->lockForUpdate()->value('last_number');

            if ($next - 1 <= $last) {
                return $next - 1 === $last;
            }

            DB::table('invoice_series')->where('key', $key)->update(['last_number' => $next - 1, 'updated_at' => now()]);

            return true;
        });
    }
}
