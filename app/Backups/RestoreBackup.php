<?php

namespace App\Backups;

use App\Models\ActivityLog;
use App\Plugins\PluginManager;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

/**
 * Puts a RunBackup zip back (gaiacp:restore): the database replaced by the dump, the files written to their places, the
 * APP_KEY set in .env when the zip carries one, then the database changes of a newer GaiaCP applied. Files that are not
 * in the backup are left where they are, and nothing outside storage/app and plugins/ is ever written.
 */
class RestoreBackup
{
    /** Where a zip's files/ entries may go, by their path from the GaiaCP folder. */
    private const FILE_ROOTS = ['storage/app/public/', 'storage/app/private/', 'plugins/'];

    private ZipArchive $zip;

    private string $sqlEntry;

    public function __construct(private string $path, private ?string $password = null)
    {
        if (! is_file($path)) {
            throw new RuntimeException("No file at {$path}.");
        }

        $this->zip = new ZipArchive;

        if ($this->zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('This is not a zip file.');
        }

        $first = (string) $this->zip->getNameIndex(0);

        if (! str_ends_with($first, '.sql')) {
            throw new RuntimeException('This zip is not a GaiaCP backup: it has no SQL dump first.');
        }

        $this->sqlEntry = $first;

        if ($password !== null && $password !== '') {
            $this->zip->setPassword($password);
        }
    }

    public function __destruct()
    {
        @$this->zip->close();
    }

    public function encrypted(): bool
    {
        return ($this->zip->statIndex(0)['encryption_method'] ?? 0) !== ZipArchive::EM_NONE;
    }

    /**
     * What it holds, read before anything changes; throws when the password is missing or wrong.
     *
     * @return array{driver: ?string, created: ?string, files: int, key: bool}
     */
    public function inspect(): array
    {
        $stream = $this->zip->getStream($this->sqlEntry);
        $head = $stream === false ? false : fread($stream, 1024);

        if ($head === false || ! str_starts_with($head, '--')) {
            throw new RuntimeException($this->encrypted()
                ? ($this->password ? 'The zip password is wrong.' : 'The zip is protected with a password: give it with --password.')
                : 'The SQL dump in the zip cannot be read.');
        }

        preg_match('/^-- Database: .* \((\w+)\)$/m', $head, $driver);
        preg_match('/^-- Created: (\S+)$/m', $head, $created);

        return [
            'driver' => $driver[1] ?? null,
            'created' => $created[1] ?? null,
            'files' => count($this->fileEntries()),
            'key' => $this->zip->locateName(RunBackup::KEY_ENTRY) !== false,
        ];
    }

    /**
     * @param  bool  $migrate  apply the database changes of this GaiaCP after (off only for a connection that isn't the app's)
     * @return array{tables: int, files: int, key: string} key: 'set', 'same', 'not-writable' or 'none'
     */
    public function __invoke(?string $connection = null, bool $migrate = true): array
    {
        $this->inspect(); // the files' paths are checked before the database is touched
        $tables = $this->database($connection);
        $files = $this->restoreFiles();
        $key = $this->restoreKey();

        if ($migrate) {
            Artisan::call('migrate', ['--force' => true, '--database' => $connection]);
        }

        ActivityLog::record('backup.restored', sprintf('Backup %s restored: %d tables, %d files%s', basename($this->path), $tables, $files, $key === 'set' ? ', APP_KEY set from the backup' : ''));

        return ['tables' => $tables, 'files' => $files, 'key' => $key];
    }

    /** Only the database, as it was (the updater after a failed migration); @return int tables */
    public function database(?string $connection = null): int
    {
        $info = $this->inspect();
        $db = DB::connection($connection);

        if ($info['driver'] !== null && $this->engine($info['driver']) !== $this->engine($db->getDriverName())) {
            throw new RuntimeException("This backup is of a {$info['driver']} database and restores only into one (this GaiaCP uses {$db->getDriverName()}).");
        }

        $tables = $this->restoreDatabase($db);
        Cache::flush(); // settings and the rest were cached from the database that is gone

        return $tables;
    }

    private function engine(string $driver): string
    {
        return $driver === 'mariadb' ? 'mysql' : $driver;
    }

    private function restoreDatabase(Connection $db): int
    {
        $sql = storage_path('app/private/restore-'.Str::random(16).'.sql');
        File::ensureDirectoryExists(dirname($sql), 0700);

        try {
            $this->extractTo($this->sqlEntry, $sql);
            $db->getSchemaBuilder()->dropAllTables();

            if ($db->getDriverName() === 'sqlite') {
                // SQLite keeps newlines inside quoted values, so the dump is not split into statements
                $db->unprepared((string) file_get_contents($sql));
            } else {
                $this->runStatements($db, $sql);
            }
        } finally {
            File::delete($sql);
        }

        return count($db->getSchemaBuilder()->getTableListing(schemaQualified: false));
    }

    /** One statement at a time, so a big dump never sits in memory whole: MySQL dumps escape newlines in values, so a line ending in ; ends a statement. */
    private function runStatements(Connection $db, string $path): void
    {
        $in = fopen($path, 'rb');
        $statement = '';

        try {
            while (($line = fgets($in)) !== false) {
                if ($statement === '' && (trim($line) === '' || str_starts_with($line, '--'))) {
                    continue;
                }

                $statement .= $line;

                if (str_ends_with(rtrim($line, "\r\n"), ';')) {
                    $db->unprepared($statement);
                    $statement = '';
                }
            }

            if (trim($statement) !== '') {
                $db->unprepared($statement);
            }
        } finally {
            fclose($in);
        }
    }

    /** @return array<string, string> zip entry => path from the GaiaCP folder */
    private function fileEntries(): array
    {
        $entries = [];

        for ($i = 0; $i < $this->zip->numFiles; $i++) {
            $name = (string) $this->zip->getNameIndex($i);
            $relative = Str::after($name, RunBackup::FILES_PREFIX);

            if (! str_starts_with($name, RunBackup::FILES_PREFIX) || str_ends_with($name, '/')) {
                continue;
            }

            // Only the folders a backup writes, and never a way out of them
            if (! Str::startsWith($relative, self::FILE_ROOTS) || str_contains($relative, '\\') || in_array('..', explode('/', $relative), true)) {
                throw new RuntimeException("The zip has a file outside GaiaCP's folders: {$name}.");
            }

            $entries[$name] = $relative;
        }

        return $entries;
    }

    private function restoreFiles(): int
    {
        $entries = $this->fileEntries();

        foreach ($entries as $entry => $relative) {
            $target = str_starts_with($relative, 'plugins/')
                ? PluginManager::directory().'/'.Str::after($relative, 'plugins/')
                : storage_path(Str::after($relative, 'storage/'));

            File::ensureDirectoryExists(dirname($target));
            $this->extractTo($entry, $target);
        }

        return count($entries);
    }

    private function restoreKey(): string
    {
        $key = $this->zip->locateName(RunBackup::KEY_ENTRY) === false ? null : trim((string) $this->zip->getFromName(RunBackup::KEY_ENTRY));

        if (! $key) {
            return 'none';
        }

        if ($key === config('app.key')) {
            return 'same';
        }

        $env = app()->environmentFilePath();
        $contents = is_file($env) ? (string) file_get_contents($env) : '';

        if (! is_writable($env) || ! preg_match('/^APP_KEY=.*$/m', $contents)) {
            return 'not-writable';
        }

        file_put_contents($env, preg_replace_callback('/^APP_KEY=.*$/m', fn () => 'APP_KEY='.$key, $contents, 1));
        config(['app.key' => $key]);
        app()->forgetInstance('encrypter');

        return 'set';
    }

    private function extractTo(string $entry, string $target): void
    {
        $in = $this->zip->getStream($entry);
        $out = $in === false ? false : fopen($target, 'wb');

        if ($in === false || $out === false) {
            throw new RuntimeException("Cannot extract {$entry} to {$target}.");
        }

        try {
            if (stream_copy_to_stream($in, $out) === false) {
                throw new RuntimeException("Cannot write {$target} (disk full?).");
            }
        } finally {
            fclose($in);
            fclose($out);
        }
    }
}
