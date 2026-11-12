<?php

namespace App\Reports;

use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A report as a CSV download: UTF-8 with a BOM, so Excel shows Greek text, and amounts as plain decimals ("12.50").
 * A text cell that a spreadsheet would read as a formula (=, +, -, @, tab or carriage return first, e.g. a client name
 * typed as "=HYPERLINK(…)") gets a ' in front, so it shows as text; numbers ("-12.50") and signed percentages ("+50%")
 * stay as they are.
 */
final class Csv
{
    /**
     * @param  list<string>  $header
     * @param  iterable<array<int, scalar|null>>  $rows
     */
    public static function download(string $filename, array $header, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($header, $rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $header, escape: '');

            foreach ($rows as $row) {
                fputcsv($out, array_map(self::cell(...), array_values($row)), escape: '');
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** The value as it goes into the file: a text that starts like a formula is kept as text. */
    public static function cell(string|int|float|bool|null $value): string|int|float|bool|null
    {
        if (! is_string($value) || $value === '' || preg_match('/^[+-]?\d[\d.,]*%?$/', $value) === 1 || ! in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return $value;
        }

        return "'".$value;
    }
}
