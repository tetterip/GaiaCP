<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An index on every foreign key column that has none. MySQL and MariaDB make one for each foreign key themselves, so
 * there this finds nothing to do; SQLite does not, and with thousands of clients a client's page, the services report
 * ("Paid to date") and the notices read whole tables for each row (found with database/seeders/LargeDataSeeder.php).
 * A later migration that adds a foreign key adds its index too: tests/Feature/ForeignKeyIndexTest.php checks it.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (self::missing() as [$table, $column]) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->index($column, self::name($table, $column)));
        }
    }

    public function down(): void
    {
        foreach (Schema::getTableListing(schemaQualified: false) as $table) {
            foreach (Schema::getIndexes($table) as $index) {
                if (str_ends_with($index['name'], '_fk_index')) {
                    Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropIndex($index['name']));
                }
            }
        }
    }

    /** @return list<array{string, string}> table and column of each foreign key without an index that starts with it */
    public static function missing(): array
    {
        $missing = [];

        foreach (Schema::getTableListing(schemaQualified: false) as $table) {
            $indexed = array_map(fn (array $index) => $index['columns'][0] ?? null, Schema::getIndexes($table));

            foreach (Schema::getForeignKeys($table) as $key) {
                $column = $key['columns'][0];

                if (count($key['columns']) === 1 && ! in_array($column, $indexed, true)) {
                    $missing[] = [$table, $column];
                    $indexed[] = $column;
                }
            }
        }

        return $missing;
    }

    private static function name(string $table, string $column): string
    {
        $name = "{$table}_{$column}_fk_index";

        return strlen($name) <= 64 ? $name : substr($name, 0, 47).'_'.substr(md5($name), 0, 7).'_fk_index';
    }
};
