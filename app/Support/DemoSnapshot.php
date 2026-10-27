<?php

namespace App\Support;

use App\Models\ActivityLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * The demo's data (gaiacp:demo-reset): a SQLite file migrated and seeded with Database\Seeders\DemoSeeder in a process
 * of its own (GAIACP_DEMO_BUILD points it at the file before anything reads the database), kept under
 * storage/app/private/demo and built again each day, since the made-up dates are relative to today, and whenever the
 * migrations change. A reset copies every table of it into the live database in one transaction, so a visitor never
 * sees half of it; sessions stay, so whoever is signed in stays signed in. Files uploaded meanwhile are deleted.
 */
class DemoSnapshot
{
    /** Tables a reset leaves alone: who is signed in, and the scheduler's locks (this reset holds one). */
    private const KEPT = ['sessions', 'cache_locks', 'migrations'];

    /** Folders of uploads a reset empties (Filament's temporary uploads, ticket files, logos, plugin zips). */
    private const UPLOADS = ['local' => ['livewire-tmp', 'tickets', 'plugin-uploads'], 'public' => ['branding']];

    public function path(): string
    {
        return storage_path('app/private/demo/demo.sqlite');
    }

    /** Whether the snapshot is from today and from these migrations. */
    public function current(): bool
    {
        $meta = is_file($file = $this->metaPath()) ? json_decode((string) file_get_contents($file), true) : null;

        return is_file($this->path())
            && ($meta['built_on'] ?? null) === today()->toDateString()
            && ($meta['migrations'] ?? null) === $this->migrationsHash();
    }

    /** Builds the snapshot again, next to the old one until it is ready. */
    public function build(): void
    {
        File::ensureDirectoryExists(dirname($this->path()));
        $building = $this->path().'.building';
        File::delete($building);
        touch($building);

        $env = ['GAIACP_DEMO' => 'true', 'GAIACP_DEMO_BUILD' => $building];

        foreach ([['migrate', '--force'], ['db:seed', '--class=Database\\Seeders\\DemoSeeder', '--force']] as $command) {
            $result = Process::path(base_path())->env($env)->timeout(300)
                ->run([PHP_BINARY, 'artisan', ...$command, '--no-interaction']);

            if ($result->failed()) {
                File::delete($building);

                throw new RuntimeException(trim($command[0].' failed: '.$result->errorOutput()."\n".$result->output()));
            }
        }

        rename($building, $this->path());
        file_put_contents($this->metaPath(), json_encode(['built_on' => today()->toDateString(), 'migrations' => $this->migrationsHash()]));
    }

    /** Puts the snapshot's rows into the live database and deletes the files uploaded since. */
    public function restore(): void
    {
        $connection = DB::connection();

        if ($connection->getDriverName() !== 'sqlite') {
            throw new RuntimeException('The demo runs on SQLite (DB_CONNECTION=sqlite).');
        }

        if (! is_file($this->path())) {
            throw new RuntimeException('There is no demo snapshot yet: run gaiacp:demo-reset --rebuild.');
        }

        $connection->statement('PRAGMA foreign_keys = OFF');
        $connection->statement('ATTACH DATABASE ? AS demo_snapshot', [$this->path()]);

        try {
            $tables = collect($connection->select("SELECT name FROM demo_snapshot.sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'"))
                ->pluck('name')
                ->reject(fn (string $table) => in_array($table, self::KEPT, true));

            $connection->transaction(function () use ($connection, $tables) {
                foreach ($tables as $table) {
                    $columns = collect($connection->select("PRAGMA demo_snapshot.table_info(\"{$table}\")"))->pluck('name')
                        ->map(fn (string $column) => "\"{$column}\"")->implode(', ');
                    $connection->statement("DELETE FROM main.\"{$table}\"");
                    $connection->statement("INSERT INTO main.\"{$table}\" ({$columns}) SELECT {$columns} FROM demo_snapshot.\"{$table}\"");
                }

                $connection->statement('DELETE FROM main.sqlite_sequence');
                $connection->statement('INSERT INTO main.sqlite_sequence SELECT * FROM demo_snapshot.sqlite_sequence');
            });
        } finally {
            $connection->statement('DETACH DATABASE demo_snapshot');
            $connection->statement('PRAGMA foreign_keys = ON');
        }

        foreach (self::UPLOADS as $disk => $folders) {
            foreach ($folders as $folder) {
                Storage::disk($disk)->deleteDirectory($folder);
            }
        }

        // Sessions stay, so what they hold of the old data (a cart) can tell it is from before this reset
        touch($this->restoredMarker());

        ActivityLog::record('demo.reset', 'Demo data put back');
    }

    /** When the data was last put back (a Unix time), or null before the first reset. */
    public function restoredAt(): ?int
    {
        clearstatcache(true, $this->restoredMarker());

        return is_file($this->restoredMarker()) ? (int) filemtime($this->restoredMarker()) : null;
    }

    private function restoredMarker(): string
    {
        return dirname($this->path()).'/restored';
    }

    private function metaPath(): string
    {
        return dirname($this->path()).'/demo.json';
    }

    /** The migration files of GaiaCP and its plugins, by name. */
    private function migrationsHash(): string
    {
        $files = collect([database_path('migrations'), ...app('migrator')->paths()])
            ->flatMap(fn (string $path) => glob($path.'/*.php') ?: [])
            ->map(fn (string $file) => basename($file))
            ->sort()->values();

        return hash('sha256', $files->implode("\n"));
    }
}
