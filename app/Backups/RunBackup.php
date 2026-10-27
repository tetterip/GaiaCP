<?php

namespace App\Backups;

use App\Mail\BackupMail;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * One backup: dump the database to SQL, zip it (with AES-256 when a zip password is set), keep it in
 * storage/app/private/backups, then copy it to FTP and email it as Settings → Backups says.
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

    public function __construct(private DatabaseDumper $dumper) {}

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

    public function __invoke(): BackupResult
    {
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
            $this->dumper->dump($sqlPath, withoutData: config('gaiacp.backups.include_logs') ? [] : self::LOG_TABLES);

            $zip = new ZipArchive;

            if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException("Cannot create {$zipPath}.");
            }

            $entry = basename($sqlPath);
            $zip->addFile($sqlPath, $entry);

            if ($password = config('gaiacp.backups.zip_password')) {
                $zip->setEncryptionName($entry, ZipArchive::EM_AES_256, $password);
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
