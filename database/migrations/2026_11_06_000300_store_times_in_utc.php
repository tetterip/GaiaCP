<?php

use App\Support\Region;
use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Times are stored in UTC from now on (App\Database\SendsTimesInUtc, App\Models\Concerns\StoresTimesInUtc). Until now
 * they were written in the timezone of Settings → General → Region: this converts every time column of every table,
 * plugins' included, row by row so each one gets the summer or winter offset of its own date. Plain date columns
 * (due dates) are days of the calendar and stay. Nothing to do on an install in UTC. down() puts them back.
 *
 * MySQL / MariaDB converted `timestamp` columns from the session's timezone, which GaiaCP did not set (the server's
 * own): the old values are read in that one and the new ones written in UTC, as the connection now always is.
 */
return new class extends Migration
{
    /** Already in UTC (2026_11_06_000200_store_server_checks_in_utc), or Laravel's own */
    private const SKIP = ['server_monitor_checks', 'migrations'];

    private const CHUNK = 500;

    public function up(): void
    {
        $this->convert(Region::timezone(), 'UTC', fromServerZone: true);
    }

    public function down(): void
    {
        $this->convert('UTC', Region::timezone(), fromServerZone: false);
    }

    private function convert(string $from, string $to, bool $fromServerZone): void
    {
        if ($from === $to) {
            return;
        }

        $mysql = in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
        $serverZone = fn () => $mysql && DB::statement('SET time_zone = @@global.time_zone');
        $utc = fn () => $mysql && DB::statement("SET time_zone = '+00:00'");
        [$reading, $writing] = $fromServerZone ? [$serverZone, $utc] : [$utc, $serverZone];

        try {
            foreach (Schema::getTableListing(schemaQualified: false) as $table) {
                $columns = collect(Schema::getColumns($table))
                    ->filter(fn (array $column) => in_array(strtolower($column['type_name']), ['datetime', 'timestamp'], true))
                    ->pluck('name')->all();
                $key = collect(Schema::getIndexes($table))->firstWhere('primary', true)['columns'] ?? [];

                if (in_array($table, self::SKIP, true) || $columns === []) {
                    continue;
                }

                if (count($key) !== 1) {
                    throw new RuntimeException("The times of the table {$table} cannot be converted to UTC: it has no single-column primary key.");
                }

                $this->convertTable($table, $key[0], $columns, $from, $to, $reading, $writing);
            }
        } finally {
            $utc();
        }
    }

    /** @param list<string> $columns */
    private function convertTable(string $table, string $key, array $columns, string $from, string $to, Closure $reading, Closure $writing): void
    {
        $after = null;

        do {
            $reading();
            $rows = DB::table($table)->select([$key, ...$columns])
                ->when($after !== null, fn ($query) => $query->where($key, '>', $after))
                ->orderBy($key)->limit(self::CHUNK)->get();

            $writing();
            DB::transaction(function () use ($rows, $table, $key, $columns, $from, $to) {
                foreach ($rows as $row) {
                    $changes = [];
                    foreach ($columns as $column) {
                        if ($row->{$column} !== null && $row->{$column} !== '') {
                            $changes[$column] = CarbonImmutable::parse($row->{$column}, $from)->setTimezone($to)->format('Y-m-d H:i:s');
                        }
                    }

                    if ($changes !== []) {
                        DB::table($table)->where($key, $row->{$key})->update($changes);
                    }
                }
            });

            $after = $rows->last()?->{$key};
        } while ($rows->count() === self::CHUNK);
    }
};
