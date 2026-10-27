<?php

namespace App\Backups;

use FTP\Connection;
use RuntimeException;

/**
 * Copies backup files to the FTP server from Settings → Backups (plain FTP or explicit FTPS) and
 * keeps only the newest ones there. Uses PHP's ftp extension; tests swap this class for a fake.
 */
class FtpUploader
{
    /**
     * @param  array{protocol?: string, host?: string, port?: int|string|null, username?: string, password?: string, path?: string, passive?: bool}  $config
     */
    private ?string $error = null;

    public function __construct(private array $config = []) {}

    public static function fromSettings(): static
    {
        return app(static::class, ['config' => (array) config('gaiacp.backups.ftp')]);
    }

    /** Log in and open the folder (creating it): the Test FTP button. */
    public function test(): void
    {
        $this->session(fn () => null);
    }

    /** Upload the file into the folder, then delete the oldest backups beyond $keep. @return list<string> deleted file names */
    public function upload(string $localPath, int $keep): array
    {
        return $this->session(function ($ftp) use ($localPath, $keep): array {
            $name = basename($localPath);

            // Under a temporary name first, so a broken upload never looks like a backup.
            if (! $this->attempt(fn () => ftp_put($ftp, "{$name}.part", $localPath, FTP_BINARY)) || ! $this->attempt(fn () => ftp_rename($ftp, "{$name}.part", $name))) {
                $error = $this->lastError();
                @ftp_delete($ftp, "{$name}.part");

                throw new RuntimeException("Upload of {$name} failed: {$error}");
            }

            $backups = collect(@ftp_nlist($ftp, '.') ?: [])
                ->map(fn (string $file) => basename($file))
                ->filter(fn (string $file) => RunBackup::isBackupFile($file))
                ->sort()
                ->values();

            $old = $backups->slice(0, max(0, $backups->count() - $keep))->values()->all();

            foreach ($old as $file) {
                @ftp_delete($ftp, $file);
            }

            return $old;
        });
    }

    /**
     * @template T
     *
     * @param  callable(Connection): T  $callback
     * @return T
     */
    private function session(callable $callback): mixed
    {
        if (! function_exists('ftp_connect')) {
            throw new RuntimeException('PHP\'s ftp extension is not installed on this server.');
        }

        $host = trim((string) ($this->config['host'] ?? ''));

        if ($host === '') {
            throw new RuntimeException('No FTP host set.');
        }

        $port = (int) ($this->config['port'] ?? 0) ?: 21;
        $ssl = ($this->config['protocol'] ?? 'ftp') === 'ftps';

        $ftp = $this->attempt(fn () => $ssl ? ftp_ssl_connect($host, $port, 30) : ftp_connect($host, $port, 30));

        if ($ftp === false) {
            throw new RuntimeException("Cannot connect to {$host}:{$port}".($ssl ? ' over FTPS' : '').': '.$this->lastError().'.');
        }

        try {
            if (! $this->attempt(fn () => ftp_login($ftp, (string) ($this->config['username'] ?? ''), (string) ($this->config['password'] ?? '')))) {
                throw new RuntimeException("Login to {$host} failed: ".$this->lastError());
            }

            ftp_set_option($ftp, FTP_USEPASVADDRESS, false); // behind NAT the server often reports a private address
            @ftp_pasv($ftp, (bool) ($this->config['passive'] ?? true));

            $this->openFolder($ftp, (string) ($this->config['path'] ?? ''));

            return $callback($ftp);
        } finally {
            @ftp_close($ftp);
        }
    }

    /** cd into the folder, creating each missing level. */
    private function openFolder($ftp, string $path): void
    {
        $path = trim($path);

        if ($path === '' || $path === '/') {
            return;
        }

        if (str_starts_with($path, '/')) {
            @ftp_chdir($ftp, '/');
        }

        foreach (array_filter(explode('/', $path), fn ($part) => $part !== '') as $part) {
            if (! $this->attempt(fn () => ftp_chdir($ftp, $part)) && (! $this->attempt(fn () => ftp_mkdir($ftp, $part)) || ! $this->attempt(fn () => ftp_chdir($ftp, $part)))) {
                throw new RuntimeException("Cannot open or create the folder {$path}: ".$this->lastError());
            }
        }
    }

    /** Run an ftp_* call, keeping its warning (the server's reply) for the error message. */
    private function attempt(callable $call): mixed
    {
        $this->error = null;
        set_error_handler(function (int $level, string $message): bool {
            $this->error = preg_replace('/^ftp_\w+\(\):\s*/', '', $message);

            return true;
        });

        try {
            return $call();
        } finally {
            restore_error_handler();
        }
    }

    private function lastError(): string
    {
        return $this->error ?? 'the server refused it';
    }
}
