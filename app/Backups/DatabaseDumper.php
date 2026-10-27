<?php

namespace App\Backups;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Writes the whole database as SQL (structure + data), in PHP so it needs no mysqldump on the server.
 * MySQL/MariaDB in production, SQLite in development and tests; the dump restores on the same engine.
 * Tables named in $withoutData keep their structure but none of their rows (logs, sessions, cache).
 */
class DatabaseDumper
{
    private const ROWS_PER_INSERT = 100;

    /** @var list<string> */
    private array $withoutData = [];

    /**
     * @param  list<string>  $withoutData  tables written empty
     * @return int tables written
     */
    public function dump(string $path, ?string $connection = null, array $withoutData = []): int
    {
        $this->withoutData = $withoutData;
        $db = DB::connection($connection);
        $out = fopen($path, 'wb');

        if ($out === false) {
            throw new RuntimeException("Cannot write {$path}.");
        }

        try {
            $tables = match ($db->getDriverName()) {
                'mysql', 'mariadb' => $this->dumpMysql($db, $out),
                'sqlite' => $this->dumpSqlite($db, $out),
                default => throw new RuntimeException("Backups do not support the {$db->getDriverName()} database driver."),
            };
        } finally {
            fclose($out);
        }

        return $tables;
    }

    /** @param  resource  $out */
    private function dumpMysql(Connection $db, $out): int
    {
        $tables = collect($db->select("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'"))
            ->map(fn (object $row) => array_values((array) $row)[0])
            ->all();

        $this->write($out, $this->header($db)."SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\nSET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n\n");

        foreach ($tables as $table) {
            $create = array_values((array) $db->selectOne('SHOW CREATE TABLE `'.str_replace('`', '``', $table).'`'))[1];

            $this->write($out, "DROP TABLE IF EXISTS `{$table}`;\n{$create};\n\n");
            $this->rows($db, $out, $table, fn (string $name) => '`'.str_replace('`', '``', $name).'`');
        }

        $this->write($out, "SET FOREIGN_KEY_CHECKS = 1;\n");

        return count($tables);
    }

    /** @param  resource  $out */
    private function dumpSqlite(Connection $db, $out): int
    {
        $tables = $db->select("SELECT name, sql FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name");
        $indexes = $db->select("SELECT sql FROM sqlite_master WHERE type = 'index' AND sql IS NOT NULL ORDER BY name");
        $quote = fn (string $name) => '"'.str_replace('"', '""', $name).'"';

        $this->write($out, $this->header($db)."PRAGMA foreign_keys = OFF;\nBEGIN TRANSACTION;\n\n");

        foreach ($tables as $table) {
            $this->write($out, 'DROP TABLE IF EXISTS '.$quote($table->name).";\n{$table->sql};\n\n");
            $this->rows($db, $out, $table->name, $quote);
        }

        foreach ($indexes as $index) {
            $this->write($out, "{$index->sql};\n");
        }

        $this->write($out, "\nCOMMIT;\nPRAGMA foreign_keys = ON;\n");

        return count($tables);
    }

    /**
     * @param  resource  $out
     * @param  callable(string): string  $quote
     */
    private function rows(Connection $db, $out, string $table, callable $quote): void
    {
        if (in_array($table, $this->withoutData, true)) {
            return;
        }

        $pdo = $db->getPdo();
        $batch = [];
        $columns = null;

        foreach ($db->table($table)->cursor() as $row) {
            $row = (array) $row;
            $columns ??= implode(', ', array_map($quote, array_keys($row)));
            $batch[] = '('.implode(', ', array_map(fn ($value) => match (true) {
                $value === null => 'NULL',
                is_bool($value) => $value ? '1' : '0',
                is_int($value), is_float($value) => (string) $value,
                default => $pdo->quote((string) $value),
            }, $row)).')';

            if (count($batch) === self::ROWS_PER_INSERT) {
                $this->write($out, 'INSERT INTO '.$quote($table)." ({$columns}) VALUES\n".implode(",\n", $batch).";\n");
                $batch = [];
            }
        }

        if ($batch !== []) {
            $this->write($out, 'INSERT INTO '.$quote($table)." ({$columns}) VALUES\n".implode(",\n", $batch).";\n");
        }

        if ($columns !== null) {
            $this->write($out, "\n");
        }
    }

    private function header(Connection $db): string
    {
        return sprintf("-- %s database backup\n-- Database: %s (%s)\n-- Created: %s\n\n", config('app.name'), $db->getDatabaseName(), $db->getDriverName(), now()->toIso8601String());
    }

    /** @param  resource  $out */
    private function write($out, string $sql): void
    {
        if (fwrite($out, $sql) === false) {
            throw new RuntimeException('Cannot write the database dump (disk full?).');
        }
    }
}
