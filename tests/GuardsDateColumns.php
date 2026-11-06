<?php

namespace Tests;

use DateTimeInterface;
use Illuminate\Database\Connection;
use LogicException;

/**
 * Times go to the database in UTC (App\Database\SendsTimesInUtc), so a Carbon compared with or written to a plain date
 * column (due_date, next_due_date) would land on the previous day east of UTC. This fails the test that sends one:
 * give a date column a date string ('2026-11-06', toDateString()) or use whereOnDate().
 */
class GuardsDateColumns
{
    /** @var array<string, array<string, true>>|null table => its date columns */
    private static ?array $dateColumns = null;

    private static bool $reading = false;

    public static function check(string $query, array $bindings, Connection $connection): void
    {
        if (self::$reading || ! array_filter($bindings, fn ($value) => $value instanceof DateTimeInterface)) {
            return;
        }

        $placeholders = self::placeholders($query);
        $table = preg_match('/\b(?:from|update|into)\s+[`"](\w+)[`"]/i', $query, $m) ? $m[1] : null;
        $inserted = self::insertedColumns($query);

        foreach (array_values($bindings) as $i => $value) {
            if (! $value instanceof DateTimeInterface || ! isset($placeholders[$i])) {
                continue;
            }

            $before = substr($query, 0, $placeholders[$i]);
            [$on, $column] = match (true) {
                $inserted !== null => [$table, $inserted[$i % count($inserted)] ?? null],
                (bool) preg_match('/(?:[`"](\w+)[`"]\.)?[`"](\w+)[`"]\s*(?:=|<>|!=|<=|>=|<|>)\s*$/', $before, $m) => [$m[1] ?: $table, $m[2]],
                (bool) preg_match('/(?:[`"](\w+)[`"]\.)?[`"](\w+)[`"]\s+(?:not\s+)?between\s+(?:\?\s+and\s+)?$/i', $before, $m) => [$m[1] ?: $table, $m[2]],
                default => [null, null],
            };

            if ($on !== null && $column !== null && isset(self::dateColumns($connection)[$on][$column])) {
                throw new LogicException("A date or time object was sent for the date column {$on}.{$column}, and would be converted to UTC: give it a date string (toDateString()) or use whereOnDate(). SQL: {$query}");
            }
        }
    }

    /** @return list<int> the offset of each "?" outside quoted text */
    private static function placeholders(string $query): array
    {
        $offsets = [];
        $quote = null;
        for ($i = 0, $length = strlen($query); $i < $length; $i++) {
            $char = $query[$i];
            if ($quote !== null) {
                $quote = $char === $quote ? null : $quote;
            } elseif ($char === "'") {
                $quote = $char;
            } elseif ($char === '?') {
                $offsets[] = $i;
            }
        }

        return $offsets;
    }

    /** @return list<string>|null the columns of an insert, in order */
    private static function insertedColumns(string $query): ?array
    {
        if (! preg_match('/^\s*insert\b[^(]*\(([^)]*)\)\s*values/i', $query, $m)) {
            return null;
        }

        return array_map(fn (string $column) => trim($column, ' `"'), explode(',', $m[1]));
    }

    /** @return array<string, array<string, true>> */
    private static function dateColumns(Connection $connection): array
    {
        if (self::$dateColumns !== null) {
            return self::$dateColumns;
        }

        self::$reading = true;
        try {
            $schema = $connection->getSchemaBuilder();
            $columns = [];
            foreach ($schema->getTableListing(schemaQualified: false) as $table) {
                foreach ($schema->getColumns($table) as $column) {
                    if (strtolower($column['type_name']) === 'date') {
                        $columns[$table][$column['name']] = true;
                    }
                }
            }
        } finally {
            self::$reading = false;
        }

        return self::$dateColumns = $columns;
    }
}
