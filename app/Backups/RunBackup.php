<?php

namespace App\Backups;

use App\Mail\BackupMail;
use App\Models\ActivityLog;
use App\Plugins\Plugin;
use App\Plugins\PluginManager;
use App\Support\Version;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * One backup: dump the database to SQL, zip it with the uploaded files (Include files), the APP_KEY (only in a zip with
 * a password) and a README on restoring it (AES-256 when a zip password is set), keep it in storage/app/private/backups,
 * then copy it to FTP and email it as Settings → Backups says. `gaiacp:restore` (RestoreBackup) puts one back.
 * Each copy is tried on its own: an FTP failure still emails, and the run reports what failed.
 */
class RunBackup
{
    /** Bigger zips are not attached (most mail servers refuse them); the email says where the file is. */
    public const MAX_ATTACHMENT_BYTES = 20 * 1024 * 1024;

    private const PREFIX = 'gaiacp-backup-';

    /**
     * Written empty unless Settings → Backups → Include logs is on: debugging history and short-lived rows,
     * which can be most of the database (the module log keeps every API reply) and are not needed to restore.
     */
    public const LOG_TABLES = ['module_logs', 'email_logs', 'sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs'];

    /** Under storage/app/private: GaiaCP's own working copies, not data (the demo's, the updater's, the plugin installer's). */
    public const SKIPPED_FOLDERS = ['backups', 'updates', 'plugins', 'demo', 'livewire-tmp', 'plugin-uploads', 'translation-uploads'];

    /** Zip entries next to the SQL dump (always the first entry): the files under FILES_PREFIX, keyed by their path from the GaiaCP folder. */
    public const FILES_PREFIX = 'files/';

    public const KEY_ENTRY = 'app-key.txt';

    public const README_ENTRY = 'README.txt';

    public function __construct(private DatabaseDumper $dumper) {}

    /** Whether the zip carries the APP_KEY: only with a zip password, so a backup found without one opens no stored secret. */
    public static function includesKey(): bool
    {
        return (bool) config('gaiacp.backups.zip_password');
    }

    /**
     * What Include files adds: storage/app/public (logo, page pictures), storage/app/private (ticket attachments, client
     * downloads, SSL files, uploaded translations…) without SKIPPED_FOLDERS and the install marker, and the plugins that
     * did not ship with GaiaCP.
     *
     * @return array<string, string> path from the GaiaCP folder => full path
     */
    public static function files(): array
    {
        $files = [];
        $add = function (string $root, string $from, ?callable $skip = null) use (&$files) {
            if (! is_dir($root)) {
                return;
            }

            foreach (File::allFiles($root, hidden: true) as $file) {
                $relative = str_replace('\\', '/', $file->getRelativePathname());

                if ($skip && $skip($relative)) {
                    continue;
                }

                $files[$from.'/'.$relative] = $file->getPathname();
            }
        };

        $add(storage_path('app/public'), 'storage/app/public');
        $add(storage_path('app/private'), 'storage/app/private', fn (string $path) => $path === 'installed'
            || in_array(strtok($path, '/'), self::SKIPPED_FOLDERS, true));

        $plugins = app(PluginManager::class);
        $plugins->all()
            ->reject(fn (Plugin $plugin) => $plugins->bundled($plugin))
            ->each(fn (Plugin $plugin) => $add($plugin->path(), 'plugins/'.basename($plugin->path())));

        // A backups folder set inside storage is never backed up into itself
        $backups = realpath(self::directory());

        return array_filter($files, fn (string $path) => $backups === false || ! str_starts_with((string) realpath($path), $backups.DIRECTORY_SEPARATOR));
    }

    public static function directory(): string
    {
        return config('gaiacp.backups.directory') ?: storage_path('app/private/backups');
    }

    public static function isBackupFile(string $name): bool
    {
        return (bool) preg_match('/^'.preg_quote(self::PREFIX, '/').'\d{4}-\d{2}-\d{2}-\d{6}\.zip$/', $name);
    }

    /** @return list<string> full paths of the backups kept on this server, newest first */
    public static function localBackups(): array
    {
        if (! is_dir(self::directory())) {
            return [];
        }

        return collect(File::files(self::directory()))
            ->map(fn ($file) => $file->getPathname())
            ->filter(fn (string $path) => self::isBackupFile(basename($path)))
            ->sortDesc()
            ->values()
            ->all();
    }

    private bool $logs = false;

    private bool $withFiles = true;

    /**
     * @param  ?bool  $includeLogs  null: as Settings → Backups says (the updater's backup keeps them, since a failed update restores it)
     * @param  ?bool  $includeFiles  null: as Settings → Backups says (the updater leaves storage alone, so it skips them)
     */
    public function __invoke(?bool $includeLogs = null, ?bool $includeFiles = null): BackupResult
    {
        $this->logs = $includeLogs ?? (bool) config('gaiacp.backups.include_logs');
        $this->withFiles = $includeFiles ?? (bool) config('gaiacp.backups.include_files');

        $result = new BackupResult;

        try {
            $result->path = $this->createZip();
            $result->size = filesize($result->path);
            $result->pruned = $this->pruneLocal();
        } catch (Throwable $e) {
            $result->errors['backup'] = $e->getMessage();
            ActivityLog::record('backup.failed', 'Backup failed: '.$e->getMessage());
            $this->mailFailure($result);

            return $result;
        }

        $name = basename($result->path);

        if (config('gaiacp.backups.ftp.enabled')) {
            try {
                FtpUploader::fromSettings()->upload($result->path, $this->keep());
                $result->copies[] = 'FTP';
            } catch (Throwable $e) {
                $result->errors['ftp'] = $e->getMessage();
            }
        }

        // The email is sent after the FTP copy so it can say whether that worked.
        if ($to = config('gaiacp.backups.email')) {
            try {
                Mail::to($to)->send(new BackupMail($result));
                $result->copies[] = 'email';
            } catch (Throwable $e) {
                $result->errors['email'] = $e->getMessage();
            }
        }

        ActivityLog::record(
            $result->ok() ? 'backup.created' : 'backup.failed',
            sprintf('Backup %s (%s), %s%s', $name, $result->sizeForHumans(), $result->copies ? 'sent to '.implode(' and ', $result->copies) : 'kept on this server only', $result->ok() ? '' : '; failed: '.implode('; ', array_map(fn ($k, $v) => "{$k}: {$v}", array_keys($result->errors), $result->errors))),
            properties: array_filter(['file' => $name, 'size' => $result->size, 'copies' => $result->copies, 'errors' => $result->errors]),
        );

        return $result;
    }

    private function createZip(): string
    {
        File::ensureDirectoryExists(self::directory(), 0700);

        $stamp = now()->format('Y-m-d-His');
        $zipPath = self::directory().'/'.self::PREFIX.$stamp.'.zip';
        $sqlPath = self::directory().'/'.self::PREFIX.$stamp.'.sql';

        try {
            $this->dumper->dump($sqlPath, withoutData: $this->logs ? [] : self::LOG_TABLES);

            $zip = new ZipArchive;

            if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException("Cannot create {$zipPath}.");
            }

            $zip->addFile($sqlPath, basename($sqlPath));

            if ($this->withFiles) {
                foreach (self::files() as $entry => $path) {
                    $zip->addFile($path, self::FILES_PREFIX.$entry);
                }
            }

            if (self::includesKey()) {
                $zip->addFromString(self::KEY_ENTRY, config('app.key')."\n");
            }

            $zip->addFromString(self::README_ENTRY, $this->readme(basename($sqlPath)));

            if ($password = config('gaiacp.backups.zip_password')) {
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $zip->setEncryptionIndex($i, ZipArchive::EM_AES_256, $password);
                }
            }

            if (! $zip->close()) {
                throw new RuntimeException('Cannot write the zip: '.$zip->getStatusString());
            }
        } catch (Throwable $e) {
            File::delete($zipPath);

            throw $e;
        } finally {
            File::delete($sqlPath);
        }

        @chmod($zipPath, 0600);

        return $zipPath;
    }

    /** Plain text, in English like the log: what the zip holds and how to put it back, for whoever opens it years later. */
    private function readme(string $sql): string
    {
        $driver = DB::connection()->getDriverName();

        return implode("\n", [
            config('app.name').' backup, made '.now()->toIso8601String().' by GaiaCP '.Version::current().' ('.$driver.' database).',
            '',
            "- {$sql}: the whole database as SQL (it restores on {$driver} only).",
            $this->withFiles ? '- files/: uploaded files (storage/app/public, storage/app/private) and plugins you added, by their path in the GaiaCP folder.' : '- Uploaded files are not included.',
            self::includesKey()
                ? '- '.self::KEY_ENTRY.': the APP_KEY from .env. It decrypts the stored server, registrar and other passwords: keep it secret.'
                : '- The APP_KEY is not included (only zips with a password carry it). Without the APP_KEY of the install that made this backup, stored server, registrar and other passwords cannot be read and must be entered again.',
            '',
            'To restore:',
            '1. Install the same GaiaCP version or a newer one (or use the existing install), with an empty or disposable database of the same kind.',
            '2. Put the zip on the server and run: php artisan gaiacp:restore /path/to/this.zip'.(config('gaiacp.backups.zip_password') ? ' --password="the zip password"' : ''),
            '   It replaces the database and the files, sets the APP_KEY when the zip has it, and applies any newer database changes.',
            '3. Without SSH: import the .sql file with phpMyAdmin (or the sqlite3 tool) into the empty database, copy what is in files/',
            '   into the GaiaCP folder and set APP_KEY in .env; for a backup of an older GaiaCP, run php artisan migrate --force once.',
            '',
            'More: System -> Help -> Keeping GaiaCP healthy -> Restoring a backup.',
            '',
        ]);
    }

    /** @return list<string> deleted file names */
    private function pruneLocal(): array
    {
        $old = array_slice(self::localBackups(), $this->keep());
        File::delete($old);

        return array_map('basename', $old);
    }

    private function keep(): int
    {
        return max(1, (int) config('gaiacp.backups.keep'));
    }

    private function mailFailure(BackupResult $result): void
    {
        if (! $to = config('gaiacp.backups.email')) {
            return;
        }

        try {
            Mail::to($to)->send(new BackupMail($result));
        } catch (Throwable $e) {
            Log::error('Backup failure email could not be sent: '.$e->getMessage());
        }
    }
}
