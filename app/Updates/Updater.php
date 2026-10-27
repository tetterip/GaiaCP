<?php

namespace App\Updates;

use App\Backups\RunBackup;
use App\Models\ActivityLog;
use App\Support\Version;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use RuntimeException;
use SodiumException;
use Throwable;
use ZipArchive;

/**
 * Updates this install to a newer release (Settings → System → Updates, `gaiacp:update`): backs up the
 * database, downloads and checks the release zip (its SHA-256, signed by a trusted key when keys are set), puts the site in maintenance mode, swaps in the new code
 * (keeping .env, storage/, the SQLite file, other plugins and anything added to public/), runs the migrations
 * and comes back up. When a step after the swap fails the old code is put back, and the database backup
 * taken first is there to restore.
 *
 * A Git checkout (staging, deployed from Git) is updated by deploying instead: update() refuses it.
 */
class Updater
{
    /**
     * Swapped, then whatever the old folder had and the release doesn't is moved back into it (database.sqlite,
     * plugins of your own, public/storage). Every other folder of the release (app, vendor, config...) replaces
     * the old one as a whole, so files a release dropped are gone.
     */
    private const MERGED = ['database', 'plugins', 'public'];

    /** Never touched. */
    private const KEPT = ['.env', 'storage', '.git'];

    private const CACHE_KEY = 'gaiacp.updates.latest';

    public function __construct(private GitHubReleases $releases) {}

    public function root(): string
    {
        return config('gaiacp.updates.root') ?? base_path();
    }

    public function isGitCheckout(): bool
    {
        return is_dir($this->root().'/.git');
    }

    /** The latest release, asked of GitHub at most once an hour unless $fresh. */
    public function latest(bool $fresh = false): ?Release
    {
        if ($fresh) {
            Cache::forget(self::CACHE_KEY);
        }

        $data = Cache::remember(self::CACHE_KEY, now()->addHour(), fn () => $this->releases->latest()?->toArray() ?? false);

        return $data ? Release::fromArray($data) : null;
    }

    public function isNewer(Release $release): bool
    {
        return version_compare($release->version, Version::current(), '>');
    }

    /**
     * @param  callable(string): void|null  $progress  told each step, for the command's output
     */
    public function update(Release $release, ?callable $progress = null): void
    {
        $say = $progress ?? fn (string $step) => null;
        $from = Version::current();

        if ($this->isGitCheckout()) {
            throw new RuntimeException('This copy is a Git checkout: update it by deploying, not from a release.');
        }
        if (! $this->isNewer($release)) {
            throw new RuntimeException("{$release->version} is not newer than {$from}.");
        }
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('Updating needs the zip PHP extension.');
        }

        $work = storage_path('app/private/updates/'.$release->version);
        File::deleteDirectory($work);
        File::ensureDirectoryExists($work);

        try {
            $say('Backing up the database');
            $backup = app(RunBackup::class)();
            if (isset($backup->errors['backup'])) {
                throw new RuntimeException('The backup failed, so nothing was changed: '.$backup->errors['backup']);
            }

            $say("Downloading GaiaCP {$release->version}");
            $source = $this->download($release, $work);
        } catch (Throwable $e) {
            File::deleteDirectory($work);
            ActivityLog::record('update.failed', "Update from {$from} to {$release->version} failed: {$e->getMessage()}");

            throw $e;
        }

        $say('Switching to maintenance mode');
        Artisan::call('down', ['--retry' => 30]);
        try {
            $say('Replacing the code');
            $this->swap($source, "{$work}/previous");

            if (function_exists('opcache_reset')) {
                @opcache_reset();
            }

            $say('Updating the database');
            $this->migrate();
        } catch (Throwable $e) {
            $this->restore("{$work}/previous");
            Artisan::call('up');
            ActivityLog::record('update.failed', "Update from {$from} to {$release->version} failed, the code of {$from} was put back: {$e->getMessage()}"
                .($backup->path ? ' Database backup from before: '.basename($backup->path) : ''));

            throw new RuntimeException("The update failed and {$from} was put back: {$e->getMessage()}", previous: $e);
        }

        Artisan::call('up');
        File::deleteDirectory($work);
        Cache::forget(self::CACHE_KEY);

        ActivityLog::record('update.completed', "Updated from {$from} to {$release->version}", properties: ['from' => $from, 'to' => $release->version]);
        $say("GaiaCP {$release->version} is installed");
    }

    /** The new release's migrations, then no cache of the old code left. */
    protected function migrate(): void
    {
        Artisan::call('migrate', ['--force' => true]);
        Artisan::call('optimize:clear');
    }

    /** Downloads, checks and unzips the release; returns the folder holding its files. */
    private function download(Release $release, string $work): string
    {
        // Every release built by .github/workflows/release.yml has one; without it a broken download is not caught
        if ($release->checksumUrl === null) {
            throw new RuntimeException('The release has no SHA-256 checksum file, so it is not installed.');
        }

        $checksum = $this->releases->text($release->checksumUrl);
        $this->verifySignature($release, $checksum);

        $zip = "{$work}/gaiacp-{$release->version}.zip";
        $this->releases->download($release->zipUrl, $zip);

        $expected = strtok(trim($checksum), " \t");
        if (! hash_equals((string) $expected, hash_file('sha256', $zip))) {
            throw new RuntimeException('The download does not match its SHA-256 checksum.');
        }

        $archive = new ZipArchive;
        if ($archive->open($zip) !== true || ! $archive->extractTo("{$work}/new")) {
            throw new RuntimeException('Could not unzip the release.');
        }
        $archive->close();

        $source = "{$work}/new/gaiacp-{$release->version}";
        $version = is_file("{$source}/VERSION") ? trim((string) file_get_contents("{$source}/VERSION")) : null;

        if ($version !== $release->version || ! is_file("{$source}/vendor/autoload.php") || ! is_file("{$source}/artisan")) {
            throw new RuntimeException("The zip is not a GaiaCP {$release->version} release.");
        }

        return $source;
    }

    /** @return list<string> the trusted public keys, raw bytes */
    public static function keys(): array
    {
        return collect(config('gaiacp.updates.keys', []))
            ->map(fn ($key) => base64_decode(trim((string) $key), true))
            ->filter(fn ($key) => is_string($key) && strlen($key) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES)
            ->values()->all();
    }

    /**
     * With trusted keys set, the release's SHA-256 file must be signed by one of them and name this version's zip, so
     * a release changed on GitHub (or an older one passed off as newer) is not installed.
     */
    private function verifySignature(Release $release, string $checksum): void
    {
        $keys = self::keys();
        if ($keys === []) {
            return;
        }

        if ($release->signatureUrl === null) {
            throw new RuntimeException('The release is not signed, so it is not installed.');
        }

        $signature = base64_decode(trim($this->releases->text($release->signatureUrl, 'signature')), true);
        $signed = is_string($signature) && strlen($signature) === SODIUM_CRYPTO_SIGN_BYTES && collect($keys)->contains(function (string $key) use ($signature, $checksum) {
            try {
                return sodium_crypto_sign_verify_detached($signature, $checksum, $key);
            } catch (SodiumException) {
                return false;
            }
        });

        if (! $signed) {
            throw new RuntimeException('The release\'s signature does not match a trusted key, so it is not installed.');
        }

        $name = trim((string) strstr(trim($checksum), ' '), " \t*");
        if ($name !== "gaiacp-{$release->version}.zip") {
            throw new RuntimeException("The signed checksum is for {$name}, not gaiacp-{$release->version}.zip, so it is not installed.");
        }
    }

    /** Root entries moved aside (restore() puts them back) and the children moved back into merged folders. */
    private array $moved = [];

    /** @var list<string> paths relative to the root */
    private array $carried = [];

    /** Moves the old code to $previous and the release's into place. */
    private function swap(string $source, string $previous): void
    {
        File::ensureDirectoryExists($previous);
        $root = $this->root();
        $this->moved = $this->carried = [];

        foreach (array_diff(scandir($source), ['.', '..', ...self::KEPT]) as $entry) {
            $old = "{$root}/{$entry}";

            if ($this->exists($old)) {
                $this->move($old, "{$previous}/{$entry}");
            }
            $this->moved[] = $entry;
            $this->move("{$source}/{$entry}", $old);

            // Moved, not copied: an open SQLite file keeps working, as rename() keeps it the same file
            if (in_array($entry, self::MERGED, true) && is_dir("{$previous}/{$entry}") && ! is_link("{$previous}/{$entry}")) {
                foreach (array_diff(scandir("{$previous}/{$entry}"), ['.', '..']) as $child) {
                    if (! $this->exists("{$old}/{$child}")) {
                        $this->move("{$previous}/{$entry}/{$child}", "{$old}/{$child}");
                        $this->carried[] = "{$entry}/{$child}";
                    }
                }
            }
        }

        // Caches built by the old code (config, routes, packages) must not outlive it
        foreach (glob("{$root}/bootstrap/cache/*.php") ?: [] as $cache) {
            @unlink($cache);
        }
    }

    /** Puts the old code back after a failed swap or migration. */
    private function restore(string $previous): void
    {
        $root = $this->root();

        foreach (array_reverse($this->carried) as $path) {
            $this->move("{$root}/{$path}", "{$previous}/{$path}");
        }

        foreach (array_reverse($this->moved) as $entry) {
            $this->delete("{$root}/{$entry}");

            if ($this->exists("{$previous}/{$entry}")) {
                $this->move("{$previous}/{$entry}", "{$root}/{$entry}");
            }
        }
    }

    private function exists(string $path): bool
    {
        return file_exists($path) || is_link($path);
    }

    private function delete(string $path): void
    {
        if (is_dir($path) && ! is_link($path)) {
            File::deleteDirectory($path);
        } elseif ($this->exists($path)) {
            @unlink($path);
        }
    }

    private function move(string $from, string $to): void
    {
        if (! @rename($from, $to)) {
            throw new RuntimeException("Could not move {$from} to {$to}: check that the web user can write to the app's folder.");
        }
    }
}
