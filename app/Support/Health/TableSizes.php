<?php

namespace App\Support\Health;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * How much room each database table takes, biggest first (System health → Biggest tables).
 * MySQL/MariaDB report data + indexes from information_schema; SQLite from its dbstat table when compiled in,
 * else only row counts (size null).
 */
class TableSizes
{
    /** @return Collection<int, array{table: string, rows: int, bytes: int|null}> */
    public function biggest(int $limit = 10): Collection
    {
        $db = DB::connection();

        try {
            $sizes = match ($db->getDriverName()) {
                'mysql', 'mariadb' => collect($db->select('SELECT TABLE_NAME AS name, DATA_LENGTH + INDEX_LENGTH AS bytes FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = ?', ['BASE TABLE']))
                    ->mapWithKeys(fn (object $row) => [$row->name => (int) $row->bytes]),
                'sqlite' => $this->sqlite(),
                default => collect(),
            };
        } catch (Throwable) {
            return collect();
        }

        $count = fn (?int $bytes, string $table) => ['table' => $table, 'rows' => DB::table($table)->count(), 'bytes' => $bytes];

        // Without sizes the most rows come first. Otherwise exact counts only for the tables shown
        // (information_schema's TABLE_ROWS is a guess for InnoDB).
        return $sizes->every(fn (?int $bytes) => $bytes === null)
            ? $sizes->map($count)->sortByDesc('rows')->take($limit)->values()
            : $sizes->sortDesc()->take($limit)->map($count)->values();
    }

    /** @return Collection<string, int|null> */
    private function sqlite(): Collection
    {
        $tables = collect(DB::select("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'"))->pluck('name');

        try {
            // Indexes count towards their table, as on MySQL
            $pages = collect(DB::select('SELECT COALESCE(m.tbl_name, s.name) AS name, SUM(s.pgsize) AS bytes FROM dbstat s LEFT JOIN sqlite_master m ON m.name = s.name GROUP BY 1'))
                ->mapWithKeys(fn (object $row) => [$row->name => (int) $row->bytes]);
        } catch (Throwable) {
            $pages = collect();
        }

        return $tables->mapWithKeys(fn (string $table) => [$table => $pages->get($table)]);
    }
}
