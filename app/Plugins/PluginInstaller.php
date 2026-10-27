<?php

namespace App\Plugins;

use App\Models\ActivityLog;
use App\Models\Setting;
use App\Support\Demo;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use ParseError;
use Throwable;
use ZipArchive;

/**
 * Installs, updates and uninstalls the plugins that don't ship with GaiaCP (Settings → Plugins). A zip is code that
 * runs with GaiaCP's full access, so it is checked before anything goes live: entries stay inside its folder (no
 * absolute or ../ paths, no symlinks), size and file count limits, a plugin.json with an x.y.z version and a class
 * in Plugins\<Folder>, every PHP file parses, it runs on this GaiaCP and isn't a bundled plugin. It is unpacked in
 * storage/ first, then moved into plugins/<Folder> and its database/migrations run. An update needs a newer version
 * and keeps the folder it replaces in storage/app/private/plugins/previous, put back if the migrations fail. A new
 * plugin starts disabled. Bundled plugins are never replaced or removed here.
 */
class PluginInstaller
{
    public const MAX_FILES = 2000;

    public const MAX_UNPACKED_BYTES = 50 * 1024 * 1024;

    public function __construct(private PluginManager $plugins) {}

    public static function uploadsAllowed(): bool
    {
        return (bool) config('gaiacp.plugins.uploads', true) && ! Demo::enabled();
    }

    /**
     * @return Plugin the installed plugin (its previous version is in PluginManager::installed() after an update)
     *
     * @throws PluginInstallException
     */
    public function install(string $zipPath): Plugin
    {
        if (! self::uploadsAllowed()) {
            throw new PluginInstallException(__('Plugin uploads are turned off on this installation (GAIACP_PLUGIN_UPLOADS).'));
        }

        $directory = PluginManager::directory();
        if (! is_dir($directory) || ! is_writable($directory)) {
            throw new PluginInstallException(__('GaiaCP can\'t write to :path. Give the web server write access to it, or copy the plugin there by hand.', ['path' => $directory]));
        }

        $work = storage_path('app/private/plugins/'.Str::random(16));
        File::ensureDirectoryExists($work);

        try {
            [$source, $manifest] = $this->unpack($zipPath, $work);
            $folder = $manifest['folder'];
            $class = $manifest['class'];
            $target = $directory.'/'.$folder;

            $this->checkPhp($source);
            $existing = $this->existing($target, $class);

            if ($existing === null && is_dir($target)) {
                throw new PluginInstallException(__('A folder named :folder is already in plugins/ but holds no plugin GaiaCP knows. Remove it first.', ['folder' => $folder]));
            }

            if ($existing !== null && ! version_compare($manifest['version'], $existing->version(), '>')) {
                throw new PluginInstallException(__(':plugin :version is already installed. Upload a newer version to update it.', ['plugin' => $existing->name(), 'version' => $existing->version()]));
            }

            $previous = null;
            $from = $existing?->version();
            if ($existing !== null) {
                $previous = storage_path('app/private/plugins/previous/'.$folder.'-'.$existing->version().'-'.now()->format('YmdHis'));
                File::ensureDirectoryExists(dirname($previous));
                $this->move($target, $previous);
            }

            $this->move($source, $target);

            try {
                $plugin = $existing ?? $this->load($class);
                $plugin->refreshManifest();
                $this->migrate($plugin);
                $this->plugins->recordVersions();
            } catch (Throwable $e) {
                File::deleteDirectory($target);
                if ($previous !== null) {
                    $this->move($previous, $target);
                    $existing->refreshManifest();
                } elseif (isset($plugin)) {
                    $this->plugins->forget($plugin);
                }

                throw $e instanceof PluginInstallException ? $e : new PluginInstallException(
                    ($previous !== null ? __('The update failed and the version before was put back: :error', ['error' => $e->getMessage()]) : __('The plugin could not be installed: :error', ['error' => $e->getMessage()])),
                    previous: $e,
                );
            }
        } finally {
            File::deleteDirectory($work);
        }

        $this->refreshCaches();

        ActivityLog::record('plugin.installed', $existing !== null
            ? "Plugin {$plugin->name()} {$plugin->version()} uploaded over {$from}"
            : "Plugin {$plugin->name()} {$plugin->version()} installed", properties: [
                'plugin' => $plugin->id(),
                'version' => $plugin->version(),
                'previous' => $from,
                'sha256' => hash_file('sha256', $zipPath),
            ]);

        return $plugin;
    }

    /**
     * Removes an uploaded plugin that is switched off: its folder and `plugins` row, and with $deleteData its tables
     * (its migrations rolled back) and settings too.
     *
     * @throws PluginInstallException
     */
    public function uninstall(Plugin $plugin, bool $deleteData = false): void
    {
        if ($this->plugins->bundled($plugin)) {
            throw new PluginInstallException(__('Plugins bundled with GaiaCP can\'t be uninstalled, only disabled.'));
        }

        if (Demo::enabled()) {
            throw new PluginInstallException(__('Plugins are not uninstalled in the demo.'));
        }

        if ($this->plugins->enabled($plugin)) {
            throw new PluginInstallException(__('Disable :plugin before uninstalling it.', ['plugin' => $plugin->name()]));
        }

        $folder = $plugin->path();
        if (! is_writable(dirname($folder))) {
            throw new PluginInstallException(__('GaiaCP can\'t write to :path. Give the web server write access to it, or remove the plugin by hand.', ['path' => dirname($folder)]));
        }

        if ($deleteData) {
            if (is_dir($plugin->migrationPath()) && Artisan::call('migrate:reset', ['--path' => $plugin->migrationPath(), '--realpath' => true, '--force' => true]) !== 0) {
                throw new PluginInstallException(__('Its tables could not be removed: :error', ['error' => trim(Artisan::output())]));
            }

            if ($plugin->settingKeys() !== []) {
                Setting::query()->whereIn('key', $plugin->settingKeys())->delete();
            }
        }

        File::deleteDirectory($folder);
        DB::table('plugins')->where('id', $plugin->id())->delete();
        $this->plugins->forget($plugin);
        $this->refreshCaches();

        ActivityLog::record('plugin.uninstalled', "Plugin {$plugin->name()} {$plugin->version()} uninstalled".($deleteData ? ' with its data' : ''), properties: ['plugin' => $plugin->id(), 'version' => $plugin->version(), 'data_deleted' => $deleteData]);
    }

    /**
     * Checks the zip and unpacks its plugin into $work.
     *
     * @return array{0: string, 1: array{folder: string, class: string, version: string}} the unpacked plugin folder and its manifest
     */
    private function unpack(string $zipPath, string $work): array
    {
        $zip = new ZipArchive;
        if ($zip->open($zipPath, ZipArchive::RDONLY) !== true) {
            throw new PluginInstallException(__('This is not a zip file.'));
        }

        try {
            if ($zip->numFiles > self::MAX_FILES) {
                throw new PluginInstallException(__('The zip holds more than :count files.', ['count' => self::MAX_FILES]));
            }

            $names = [];
            $entries = [];
            $bytes = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $name = str_replace('\\', '/', (string) $stat['name']);

                if (str_starts_with($name, '__MACOSX/') || basename($name) === '.DS_Store') {
                    continue;
                }

                if ($name === '' || str_starts_with($name, '/') || str_contains($name, "\0") || preg_match('#(^|/)\.\.(/|$)|^[A-Za-z]:#', $name)) {
                    throw new PluginInstallException(__('The zip has a file outside its folder (:name).', ['name' => $name]));
                }

                $zip->getExternalAttributesIndex($i, $system, $attributes);
                if ($system === ZipArchive::OPSYS_UNIX && (($attributes >> 16) & 0170000) === 0120000) {
                    throw new PluginInstallException(__('The zip has a symbolic link (:name), which plugins can\'t contain.', ['name' => $name]));
                }

                $bytes += (int) $stat['size'];
                $names[$i] = $name;
                $entries[] = (string) $stat['name'];
            }

            if ($bytes > self::MAX_UNPACKED_BYTES) {
                throw new PluginInstallException(__('The zip unpacks to more than :size MB.', ['size' => self::MAX_UNPACKED_BYTES / 1024 / 1024]));
            }

            // plugin.json at the top, or in the one folder at the top
            $prefix = collect($names)->filter(fn (string $name) => preg_match('#^([^/]+/)?plugin\.json$#', $name))
                ->map(fn (string $name) => substr($name, 0, -strlen('plugin.json')))->sortBy(fn (string $prefix) => strlen($prefix))->first();

            if ($prefix === null) {
                throw new PluginInstallException(__('The zip has no plugin.json at its top or in its one top folder.'));
            }

            foreach ($names as $name) {
                if (! str_starts_with($name, $prefix)) {
                    throw new PluginInstallException(__('Everything in the zip must be in the plugin\'s folder (:name is not).', ['name' => $name]));
                }
            }

            $manifest = json_decode((string) $zip->getFromName($prefix.'plugin.json'), true);
            if (! is_array($manifest)) {
                throw new PluginInstallException(__('plugin.json is not valid JSON.'));
            }

            $folder = $prefix !== '' ? rtrim($prefix, '/') : null;
            $class = isset($manifest['class']) ? ltrim((string) $manifest['class'], '\\') : ($folder !== null ? "Plugins\\{$folder}\\{$folder}Plugin" : '');
            if (! preg_match('/^Plugins\\\\([A-Za-z_][A-Za-z0-9_]*)\\\\([A-Za-z_][A-Za-z0-9_]*)$/', $class, $match)) {
                throw new PluginInstallException(__('plugin.json must name the plugin\'s class, e.g. "class": "Plugins\\\\MyPlugin\\\\MyPluginPlugin".'));
            }

            $folder = $match[1];
            if (! in_array($prefix.$match[2].'.php', $names, true)) {
                throw new PluginInstallException(__('The zip has no :file for its class :class.', ['file' => $match[2].'.php', 'class' => $class]));
            }

            $version = (string) ($manifest['version'] ?? '');
            if (! preg_match('/^\d+\.\d+\.\d+(-[0-9A-Za-z.-]+)?$/', $version)) {
                throw new PluginInstallException(__('plugin.json needs a "version" like 1.0.0.'));
            }

            $this->checkBundled($folder, $class);
            $this->checkRequires($manifest['requires'] ?? null);

            $unpacked = $work.'/unpacked';
            if (! $zip->extractTo($unpacked, $entries)) {
                throw new PluginInstallException(__('The zip could not be unpacked.'));
            }
        } finally {
            $zip->close();
        }

        return [rtrim($unpacked.'/'.$prefix, '/'), ['folder' => $folder, 'class' => $class, 'version' => $version]];
    }

    private function checkBundled(string $folder, string $class): void
    {
        $bundled = collect(PluginManager::BUILT_IN)->contains(fn (string $built) => $built === $class || strtolower(explode('\\', $built)[1]) === strtolower($folder));

        if ($bundled) {
            throw new PluginInstallException(__('This is a plugin bundled with GaiaCP: it updates together with GaiaCP.'));
        }
    }

    private function checkRequires(mixed $requires): void
    {
        $requires = filled($requires) ? (string) $requires : null;

        if (! PluginManager::runsOn($requires)) {
            throw new PluginInstallException(__('This plugin needs GaiaCP :version or later. Update GaiaCP under Settings → Updates first.', ['version' => $requires]));
        }
    }

    /** Every PHP file must parse, so a broken plugin can't stop the panel from loading. */
    private function checkPhp(string $source): void
    {
        foreach (File::allFiles($source) as $file) {
            if (strtolower($file->getExtension()) !== 'php') {
                continue;
            }

            try {
                token_get_all((string) file_get_contents($file->getPathname()), TOKEN_PARSE);
            } catch (ParseError $e) {
                throw new PluginInstallException(__(':file has a PHP error on line :line: :error', ['file' => $file->getRelativePathname(), 'line' => $e->getLine(), 'error' => $e->getMessage()]));
            }
        }
    }

    /** The uploaded plugin already in this folder, or null; a bundled one there is refused. */
    private function existing(string $target, string $class): ?Plugin
    {
        $plugin = is_dir($target) ? $this->plugins->all()->first(fn (Plugin $plugin) => realpath($plugin->path()) === realpath($target)) : null;

        if ($plugin !== null && $plugin::class !== $class) {
            throw new PluginInstallException(__('plugins/:folder holds another plugin (:plugin).', ['folder' => basename($target), 'plugin' => $plugin->name()]));
        }

        return $plugin;
    }

    /** Loads a newly placed plugin's class and adds it to this request's list. */
    private function load(string $class): Plugin
    {
        if (class_exists($class, false)) {
            throw new PluginInstallException(__(':class is already loaded from somewhere else.', ['class' => $class]));
        }

        if (! class_exists($class) || ! is_subclass_of($class, Plugin::class)) {
            throw new PluginInstallException(__(':class must extend :base.', ['class' => $class, 'base' => Plugin::class]));
        }

        $plugin = new $class;

        if (! preg_match('/^[a-z0-9][a-z0-9_-]*$/', $plugin->id())) {
            throw new PluginInstallException(__('The plugin id must be lowercase letters, digits, - or _.'));
        }

        if ($this->plugins->find($plugin->id()) !== null) {
            throw new PluginInstallException(__('Another plugin already uses the id :id.', ['id' => $plugin->id()]));
        }

        $this->plugins->register($plugin);

        return $plugin;
    }

    private function migrate(Plugin $plugin): void
    {
        if (is_dir($plugin->migrationPath()) && Artisan::call('migrate', ['--path' => $plugin->migrationPath(), '--realpath' => true, '--force' => true]) !== 0) {
            throw new PluginInstallException(__('Its database migrations failed: :error', ['error' => trim(Artisan::output())]));
        }
    }

    /** Moves a folder, copying when a rename can't (another disk). */
    private function move(string $from, string $to): void
    {
        if (! @rename($from, $to)) {
            if (! File::copyDirectory($from, $to)) {
                throw new PluginInstallException(__('Could not move the plugin into :path.', ['path' => $to]));
            }
            File::deleteDirectory($from);
        }
    }

    /**
     * New code must not be served from opcache, and cached config / routes must include (or drop) the plugin's.
     * Only those two are rebuilt: clearing compiled views or the app cache mid-request breaks the open page.
     */
    private function refreshCaches(): void
    {
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        foreach (['config' => app()->configurationIsCached(), 'route' => app()->routesAreCached()] as $cache => $cached) {
            if (! $cached) {
                continue;
            }

            try {
                Artisan::call("{$cache}:cache");
            } catch (Throwable) {
                Artisan::call("{$cache}:clear"); // left uncached: slower, still right; the next deploy caches again
            }
        }
    }
}
