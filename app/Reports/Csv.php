<?php

namespace App\Reports;

use Symfony\Component\HttpFoundation\StreamedResponse;

/** A report as a CSV download: UTF-8 with a BOM, so Excel shows Greek text, and amounts as plain decimals ("12.50"). */
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
                fputcsv($out, array_values($row), escape: '');
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
