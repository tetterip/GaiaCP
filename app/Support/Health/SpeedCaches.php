<?php

namespace App\Support\Health;

use App\Install\Installer;
use App\Models\ActivityLog;
use App\Plugins\PluginInstaller;
use BladeUI\Icons\Factory as IconFactory;
use BladeUI\Icons\IconsManifest;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

/**
 * The two speed fixes of System health that used to need a terminal:
 *
 * - The caches `php artisan optimize` writes (config, events, routes, views, and through Filament's own hook its
 *   components and icons). build() makes them; "Optimize now" on System health asks for it, and on a live site the
 *   scheduler does it within a minute whenever one is missing (after an update, which clears them) or .env changed
 *   after the config was cached (so an edited .env takes effect without optimize:clear).
 * - Cache and sessions in files instead of the database (useFiles(): .env's CACHE_STORE and SESSION_DRIVER). New
 *   installs start that way (Installer::prepareEnvironment()).
 */
class SpeedCaches
{
    /** Why the last build failed (a plain string: the cache can't hold objects everywhere); the scheduler waits a day. */
    public const FAILED = 'gaiacp.speed.optimize_failed';

    /** The commands that remove what `optimize` made, without emptying the application cache as optimize:clear does. */
    private const CLEAR = ['config:clear', 'event:clear', 'route:clear'];

    /**
     * What is cached for the next request, by the name System health shows. The files, not configurationIsCached()
     * and the like: those say how this request started, so they stay false right after a build.
     *
     * @return array<string, bool>
     */
    public function state(): array
    {
        return [
            'config' => is_file(app()->getCachedConfigPath()),
            'routes' => is_file(app()->getCachedRoutesPath()),
            'events' => is_file(app()->getCachedEventsPath()),
            'Filament components' => (glob(base_path('bootstrap/cache/filament/panels/*.php')) ?: []) !== [], // clearing leaves the empty folders
            'icons' => is_file(base_path('bootstrap/cache/blade-icons.php')),
        ];
    }

    /** @return list<string> */
    public function missing(): array
    {
        return array_keys(array_filter($this->state(), fn (bool $cached) => ! $cached));
    }

    /** .env was saved after the config was cached, so the site still runs on the old values. */
    public function stale(): bool
    {
        $config = app()->getCachedConfigPath();
        $env = app()->environmentFilePath();

        return is_file($config) && is_file($env) && filemtime($env) > filemtime($config);
    }

    public function needed(): bool
    {
        return $this->missing() !== [] || $this->stale();
    }

    public function lastFailure(): ?string
    {
        try {
            $failure = Cache::get(self::FAILED);
        } catch (Throwable) {
            return null;
        }

        return is_string($failure) ? $failure : null;
    }

    /**
     * Whether the scheduler should build the caches now: only on a live, installed site (on a developer's machine a
     * cached route file hides new routes), when something is missing or stale, and not again the day a build failed.
     */
    public function shouldAutoBuild(): bool
    {
        return app()->environment('production')
            && config('gaiacp.speed.auto_optimize', true)
            && is_file(app(Installer::class)->lockFile())
            && $this->needed()
            && $this->lastFailure() === null;
    }

    /**
     * Runs `optimize`. Rebuilding config or routes makes a second application, so it runs inside
     * PluginInstaller::keepingApplication(). A failure removes the partial caches (the site is then slower, still
     * right) and is remembered for System health and the activity log.
     *
     * @return bool whether everything is cached now
     */
    public function build(): bool
    {
        try {
            Cache::forget(self::FAILED);
            PluginInstaller::keepingApplication(fn () => Artisan::call('optimize'));
            $this->cacheFilament();
        } catch (Throwable $e) {
            $this->clear();
            $this->failed($e->getMessage());

            return false;
        }

        if (function_exists('opcache_reset')) {
            @opcache_reset(); // a PHP that never rereads files would keep the old cache files
        }

        if (($missing = $this->missing()) !== []) {
            $this->failed('Not cached afterwards: '.implode(', ', $missing));

            return false;
        }

        ActivityLog::record('speed.optimized', 'Cached config, routes, events, views and Filament components');

        return true;
    }

    /** In a web request after the response has gone out (see PluginInstaller::refreshCaches() for why), else now. */
    public function buildAfterResponse(): void
    {
        if (app()->runningUnitTests()) {
            return; // tests check the call with a mock: a real build would leave cached routes in bootstrap/cache for the next tests
        }

        if (app()->runningInConsole()) {
            $this->build();

            return;
        }

        app()->terminating(fn () => $this->build());
    }

    /**
     * Moves cache and sessions from the database to files: .env gets CACHE_STORE=file and SESSION_DRIVER=file, and
     * the config is cached again with them. Everyone signed in (admins and clients) signs in again once.
     *
     * @throws RuntimeException when the folders or .env can't be written
     */
    public function useFiles(): void
    {
        foreach ([storage_path('framework/sessions'), storage_path('framework/cache/data')] as $folder) {
            File::ensureDirectoryExists($folder);
            if (! is_writable($folder)) {
                throw new RuntimeException(__('GaiaCP cannot write to :path. Make the storage folder writable for the site and try again.', ['path' => $folder]));
            }
        }

        app(Installer::class)->writeEnv(['CACHE_STORE' => 'file', 'SESSION_DRIVER' => 'file']);
        ActivityLog::record('speed.files', 'Cache and sessions moved from the database to files (CACHE_STORE=file, SESSION_DRIVER=file in .env)');

        if (is_file(app()->getCachedConfigPath())) {
            $this->buildAfterResponse(); // the cached config still says database until it is made again
        }
    }

    /**
     * What `filament:optimize` does. Filament adds it to `optimize`, and registers its commands, only on the command
     * line, so in the browser (Optimize now) these are done here.
     */
    private function cacheFilament(): void
    {
        foreach (Filament::getPanels() as $panel) {
            $panel->cacheComponents();
        }

        app(IconsManifest::class)->write(app(IconFactory::class)->all());
    }

    private function clear(): void
    {
        foreach (Filament::getPanels() as $panel) {
            $panel->clearCachedComponents();
        }
        File::delete(base_path('bootstrap/cache/blade-icons.php'));

        foreach (self::CLEAR as $command) {
            try {
                Artisan::call($command);
            } catch (Throwable) {
                // what is left is still the old, working cache
            }
        }
    }

    private function failed(string $reason): void
    {
        try {
            Cache::put(self::FAILED, $reason, now()->addDay());
        } catch (Throwable) {
            // the scheduler then tries again next minute
        }

        ActivityLog::record('speed.optimize_failed', "Caching config, routes and Filament components failed: {$reason}");
    }
}
