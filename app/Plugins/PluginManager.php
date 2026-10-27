<?php

namespace App\Plugins;

use App\Ai\AiModule;
use App\Captcha\CaptchaModule;
use App\Certificates\CertificateModule;
use App\Events\GaiaEvent;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Monitoring\MonitoringModule;
use App\Payments\PaymentModule;
use App\Provisioning\ProvisioningException;
use App\Provisioning\RegistrarModule;
use App\Provisioning\ServerModule;
use App\Support\Demo;
use App\Support\Translations;
use App\Support\Version;
use App\Themes\ThemeModule;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Foundation\CachesConfiguration;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Database\QueryException;
use Illuminate\Database\SQLiteDatabaseDoesNotExistException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Translation\Translator;
use Plugins\Aade\AadePlugin;
use Plugins\BankTransfer\BankTransferPlugin;
use Plugins\BetterStack\BetterStackPlugin;
use Plugins\Blacklists\BlacklistsPlugin;
use Plugins\Claude\ClaudePlugin;
use Plugins\Cpanel\CpanelPlugin;
use Plugins\CyberPanel\CyberPanelPlugin;
use Plugins\DeepSeek\DeepSeekPlugin;
use Plugins\Demo\DemoPlugin;
use Plugins\DirectAdmin\DirectAdminPlugin;
use Plugins\EasyGr\EasyGrPlugin;
use Plugins\EmailRegistrar\EmailRegistrarPlugin;
use Plugins\Enhance\EnhancePlugin;
use Plugins\Enom\EnomPlugin;
use Plugins\FlatTheme\FlatThemePlugin;
use Plugins\GaiaTheme\GaiaThemePlugin;
use Plugins\GoGetSsl\GoGetSslPlugin;
use Plugins\GoogleLogin\GoogleLoginPlugin;
use Plugins\Hestia\HestiaPlugin;
use Plugins\HetrixTools\HetrixToolsPlugin;
use Plugins\Kimi\KimiPlugin;
use Plugins\OpenAi\OpenAiPlugin;
use Plugins\Plesk\PleskPlugin;
use Plugins\Proxmox\ProxmoxPlugin;
use Plugins\Recaptcha\RecaptchaPlugin;
use Plugins\SiteChecks\SiteChecksPlugin;
use Plugins\Stripe\StripePlugin;
use Plugins\Turnstile\TurnstilePlugin;
use Plugins\UptimeKuma\UptimeKumaPlugin;
use Plugins\UptimeRobot\UptimeRobotPlugin;
use Plugins\Vies\ViesPlugin;
use Plugins\WhmcsImport\WhmcsImportPlugin;
use Throwable;

/**
 * The plugins GaiaCP knows and whether each is on (`plugins.enabled`; no row = on for a bundled one, off for any
 * other), and what they add (server
 * types, registrar modules, pages, settings). Servers and registrars are built only for an enabled plugin's type (ProvisionerFactory, RegistrarFactory), pickers offer
 * only those, and a plugin's settings page hides while it is off. A plugin that needs a newer GaiaCP
 * (Plugin::requires()) counts as off. Each plugin's installed version is kept in `plugins.version`
 * (recordVersions(), after every migrate). A singleton: states are read once a request.
 *
 * Besides BUILT_IN, every folder of plugins/ (`gaiacp.plugins.directory`) with a plugin.json is found here
 * (discover()): its class is plugin.json "class", else Plugins\<Folder>\<Folder>Plugin. Those come from an upload
 * (PluginInstaller) or a copy by hand and can be uninstalled; a folder that doesn't load is listed in problems().
 */
class PluginManager
{
    /** @var list<class-string<Plugin>> shipped with GaiaCP */
    public const BUILT_IN = [CpanelPlugin::class, PleskPlugin::class, HestiaPlugin::class, DirectAdminPlugin::class, CyberPanelPlugin::class, EnhancePlugin::class, ProxmoxPlugin::class, EasyGrPlugin::class, EnomPlugin::class, EmailRegistrarPlugin::class, GoGetSslPlugin::class, AadePlugin::class, ViesPlugin::class, WhmcsImportPlugin::class, BankTransferPlugin::class, StripePlugin::class, GoogleLoginPlugin::class, RecaptchaPlugin::class, TurnstilePlugin::class, UptimeKumaPlugin::class, UptimeRobotPlugin::class, HetrixToolsPlugin::class, BetterStackPlugin::class, BlacklistsPlugin::class, SiteChecksPlugin::class, ClaudePlugin::class, DeepSeekPlugin::class, KimiPlugin::class, OpenAiPlugin::class, GaiaThemePlugin::class, FlatThemePlugin::class];

    /** @var array<string, Plugin> */
    private array $plugins = [];

    /** @var array<string, object>|null id => `plugins` row; null = not read yet */
    private ?array $rows = null;

    /** @var array<string, string> folder => why a plugin folder could not be loaded */
    private array $problems = [];

    public function __construct()
    {
        foreach (self::BUILT_IN as $class) {
            $this->register(new $class);
        }

        // The public demo's made-up panel, registrar and monitoring: there only, always on (App\Support\Demo)
        if (Demo::enabled()) {
            $this->register(new DemoPlugin);
        }

        $this->discover();
    }

    public function register(Plugin $plugin): void
    {
        $this->plugins[$plugin->id()] = $plugin;
    }

    /** Drops a plugin from this request's list (after it is uninstalled). */
    public function forget(Plugin $plugin): void
    {
        unset($this->plugins[$plugin->id()]);
    }

    /** Where plugin folders live (plugins/ of GaiaCP unless `gaiacp.plugins.directory` says otherwise). */
    public static function directory(): string
    {
        return rtrim((string) (config('gaiacp.plugins.directory') ?: base_path('plugins')), '/\\');
    }

    /** Whether it ships with GaiaCP: never uninstalled, updated with GaiaCP, on until switched off unless onByDefault() says otherwise. */
    public function bundled(Plugin $plugin): bool
    {
        return in_array($plugin::class, self::BUILT_IN, true) || $plugin instanceof DemoPlugin;
    }

    /**
     * Whether it is on before anyone switches it: bundled, and not left off by default (Plugin::enabledByDefault())
     * unless GAIACP_PLUGINS_START_ENABLED names it. Only matters until its `plugins` row exists.
     */
    public function onByDefault(Plugin $plugin): bool
    {
        return $this->bundled($plugin)
            && ($plugin->enabledByDefault() || in_array($plugin->id(), (array) config('gaiacp.plugins.start_enabled'), true));
    }

    /** @return array<string, string> folder => why it was not loaded */
    public function problems(): array
    {
        return $this->problems;
    }

    /** The plugin class a folder's plugin.json names (or the folder's <Folder>Plugin), null when it names none it could be. */
    public static function classFor(string $folder, array $manifest): ?string
    {
        $class = ltrim((string) ($manifest['class'] ?? 'Plugins\\'.$folder.'\\'.$folder.'Plugin'), '\\');

        return preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $folder) && preg_match('/^Plugins\\\\'.preg_quote($folder, '/').'\\\\[A-Za-z_][A-Za-z0-9_]*$/', $class) ? $class : null;
    }

    /** Registers each plugin folder that isn't bundled; one that fails to load is noted in problems() and skipped. */
    private function discover(): void
    {
        $directory = self::directory();
        $this->autoload($directory);
        $known = array_map(fn (Plugin $plugin) => $plugin::class, $this->plugins);

        foreach (glob($directory.'/*/plugin.json') ?: [] as $file) {
            $folder = basename(dirname($file));
            $manifest = json_decode((string) file_get_contents($file), true);
            $class = self::classFor($folder, is_array($manifest) ? $manifest : []);

            if ($class !== null && (in_array($class, $known, true) || $class === DemoPlugin::class)) {
                continue; // a bundled one, or the demo's (never loaded outside the demo)
            }

            $problem = match (true) {
                ! is_array($manifest) => 'plugin.json is not valid JSON',
                $class === null => "plugin.json \"class\" must be a class in the Plugins\\{$folder} namespace",
                ! class_exists($class) => "{$class} was not found",
                ! is_subclass_of($class, Plugin::class) => "{$class} does not extend ".Plugin::class,
                default => null,
            };

            if ($problem === null) {
                $plugin = new $class;
                $problem = isset($this->plugins[$plugin->id()]) ? "the id {$plugin->id()} is taken by another plugin" : null;
            }

            if ($problem !== null) {
                $this->problems[$folder] = $problem;

                continue;
            }

            $this->register($plugin);
        }
    }

    /** Loads Plugins\<Folder>\ classes from the plugin folder (Composer only knows plugins/ as it was at install). */
    private function autoload(string $directory): void
    {
        spl_autoload_register(function (string $class) use ($directory) {
            if (str_starts_with($class, 'Plugins\\') && is_file($file = $directory.'/'.str_replace('\\', '/', substr($class, 8)).'.php')) {
                require_once $file;
            }
        });
    }

    /** @return list<string> each plugin's database/migrations folder (run by migrate like the app's own) */
    public function migrationPaths(): array
    {
        return $this->all()->map(fn (Plugin $plugin) => $plugin->migrationPath())->filter(fn (string $path) => is_dir($path))->values()->all();
    }

    /** @return Collection<string, Plugin> */
    public function all(): Collection
    {
        return collect($this->plugins);
    }

    public function find(string $id): ?Plugin
    {
        return $this->plugins[$id] ?? null;
    }

    public function state(Plugin|string $plugin): PluginState
    {
        $plugin = $plugin instanceof Plugin ? $plugin : $this->find($plugin);

        if ($plugin === null) {
            return PluginState::Disabled;
        }

        if (! $this->compatible($plugin)) {
            return PluginState::Incompatible;
        }

        if (Demo::keepsPluginOff($plugin->id())) {
            return PluginState::OffInDemo;
        }

        if ($plugin instanceof DemoPlugin) {
            return PluginState::Enabled;
        }

        return $this->switchedOn($plugin) ? PluginState::Enabled : PluginState::Disabled;
    }

    /** Whether this GaiaCP is at least the version the plugin requires (an unknown core version runs anything). */
    public function compatible(Plugin $plugin): bool
    {
        return self::runsOn($plugin->requires());
    }

    /** Whether a plugin requiring this GaiaCP version (null = any) runs here. */
    public static function runsOn(?string $requires): bool
    {
        $core = Version::current();

        return $requires === null || $core === 'unknown' || version_compare($core, $requires, '>=');
    }

    /** No row (or no plugins table yet, mid-deploy): a bundled plugin is on, any other off until enabled. */
    private function switchedOn(Plugin $plugin): bool
    {
        $row = $this->rows()[$plugin->id()] ?? null;

        return $row === null ? $this->onByDefault($plugin) : (bool) $row->enabled;
    }

    /** The version recorded as installed here, the one before it and when it changed (null = not recorded yet). */
    public function installed(Plugin $plugin): ?object
    {
        $row = $this->rows()[$plugin->id()] ?? null;

        if ($row?->version === null) {
            return null;
        }

        return (object) [
            'version' => $row->version,
            'previous' => $row->previous_version ?? null,
            'changedAt' => filled($row->version_changed_at ?? null) ? CarbonImmutable::parse($row->version_changed_at) : null,
        ];
    }

    /**
     * Records each plugin's version as installed: a new one is noted, a changed one keeps the version it replaced
     * and logs `plugin.updated`. Safe to re-run (run after every migrate, so a deploy, gaiacp:update or install).
     */
    public function recordVersions(): void
    {
        try {
            $rows = DB::table('plugins')->get()->keyBy('id');
        } catch (QueryException|SQLiteDatabaseDoesNotExistException) {
            return;
        }

        foreach ($this->plugins as $plugin) {
            $row = $rows[$plugin->id()] ?? null;
            $version = $plugin->version();

            if ($row === null) {
                DB::table('plugins')->insert(['id' => $plugin->id(), 'enabled' => $this->onByDefault($plugin), 'version' => $version, 'version_changed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            } elseif ($row->version === null) {
                DB::table('plugins')->where('id', $plugin->id())->update(['version' => $version, 'version_changed_at' => now(), 'updated_at' => now()]);
            } elseif ($row->version !== $version) {
                DB::table('plugins')->where('id', $plugin->id())->update(['version' => $version, 'previous_version' => $row->version, 'version_changed_at' => now(), 'updated_at' => now()]);
                $verb = version_compare($version, $row->version, '>') ? 'updated' : 'changed';
                ActivityLog::record('plugin.updated', "Plugin {$plugin->name()} {$verb} from {$row->version} to {$version}", properties: ['plugin' => $plugin->id(), 'from' => $row->version, 'to' => $version]);
            }
        }

        $this->rows = null;
    }

    public function enabled(Plugin|string $plugin): bool
    {
        return $this->state($plugin) === PluginState::Enabled;
    }

    /** The plugin that provides this server type or registrar module key, enabled or not. */
    private function owner(string $key, string $kind): ?Plugin
    {
        return $this->all()->first(fn (Plugin $plugin) => collect($kind === 'server' ? $plugin->serverModules() : $plugin->registrarModules())
            ->contains(fn (ServerModule|RegistrarModule $module) => $module->key() === $key));
    }

    /** A server type's module, enabled or not (e.g. to show the label of an existing server). */
    public function serverModule(?string $key): ?ServerModule
    {
        return $this->all()->flatMap(fn (Plugin $plugin) => $plugin->serverModules())->first(fn (ServerModule $module) => $module->key() === $key);
    }

    public function registrarModule(?string $key): ?RegistrarModule
    {
        return $this->all()->flatMap(fn (Plugin $plugin) => $plugin->registrarModules())->first(fn (RegistrarModule $module) => $module->key() === $key);
    }

    /** @return Collection<string, ServerModule> key => module, of enabled plugins: what a new server can be */
    public function serverModules(): Collection
    {
        return $this->enabledPlugins()->flatMap(fn (Plugin $plugin) => $plugin->serverModules())->keyBy(fn (ServerModule $module) => $module->key());
    }

    /** @return Collection<string, RegistrarModule> key => module, of enabled plugins: what a new registrar account can use */
    public function registrarModules(): Collection
    {
        return $this->enabledPlugins()->flatMap(fn (Plugin $plugin) => $plugin->registrarModules())->keyBy(fn (RegistrarModule $module) => $module->key());
    }

    /** @return Collection<string, CertificateModule> key => module, of enabled plugins: the SSL certificate providers */
    public function certificateModules(): Collection
    {
        return $this->enabledPlugins()->flatMap(fn (Plugin $plugin) => $plugin->certificateModules())->keyBy(fn (CertificateModule $module) => $module->certificateKey());
    }

    /** A payment method's module, enabled or not (e.g. to name the method of an existing payment). */
    public function paymentModule(?string $key): ?PaymentModule
    {
        return $this->all()->flatMap(fn (Plugin $plugin) => $plugin->paymentModules())->first(fn (PaymentModule $module) => $module->key() === $key);
    }

    /** @return Collection<string, PaymentModule> key => module, of enabled plugins: how a new payment can be made */
    public function paymentModules(): Collection
    {
        return $this->enabledPlugins()->flatMap(fn (Plugin $plugin) => $plugin->paymentModules())->keyBy(fn (PaymentModule $module) => $module->key());
    }

    /** @return list<CaptchaModule> the enabled plugins' captchas */
    public function captchaModules(): array
    {
        return $this->enabledPlugins()->flatMap(fn (Plugin $plugin) => $plugin->captchaModules())->values()->all();
    }

    /** A monitoring service's module, enabled or not (e.g. to name the one a server is linked to). */
    public function monitoringModule(?string $key): ?MonitoringModule
    {
        return $this->all()->flatMap(fn (Plugin $plugin) => $plugin->monitoringModules())->first(fn (MonitoringModule $module) => $module->monitoringKey() === $key);
    }

    /** @return Collection<string, MonitoringModule> key => module, of enabled plugins: what a server can be monitored by */
    public function monitoringModules(): Collection
    {
        return $this->enabledPlugins()->flatMap(fn (Plugin $plugin) => $plugin->monitoringModules())->keyBy(fn (MonitoringModule $module) => $module->monitoringKey());
    }

    /** @return Collection<string, AiModule> the AI services of the plugins that are on, by aiKey() */
    public function aiModules(): Collection
    {
        return $this->enabledPlugins()->flatMap(fn (Plugin $plugin) => $plugin->aiModules())->keyBy(fn (AiModule $module) => $module->aiKey());
    }

    /** @return list<ThemeModule> the enabled plugins' admin panel themes */
    public function themeModules(): array
    {
        return $this->enabledPlugins()->flatMap(fn (Plugin $plugin) => $plugin->themeModules())->values()->all();
    }

    /** Hands the event to each plugin that is on and wants it (Plugin::events()); one that fails is reported and skipped. */
    public function dispatchEvent(GaiaEvent $event): void
    {
        foreach ($this->enabledPlugins() as $plugin) {
            $wanted = $plugin->events();

            if (! in_array('*', $wanted, true) && ! in_array($event->name, $wanted, true)) {
                continue;
            }

            try {
                $plugin->handleEvent($event);
            } catch (Throwable $e) {
                report($e);
            }
        }
    }

    /** @return Collection<string, Plugin> */
    public function enabledPlugins(): Collection
    {
        return $this->all()->filter(fn (Plugin $plugin) => $this->enabled($plugin));
    }

    /** @throws ProvisioningException when the plugin for this server type is off or missing */
    public function enabledServerModule(string $key): ServerModule
    {
        $plugin = $this->owner($key, 'server');

        if (! $plugin || ! $this->enabled($plugin)) {
            throw new ProvisioningException($this->offMessage($plugin, $key));
        }

        return $this->serverModule($key);
    }

    /** @throws ProvisioningException when the plugin for this registrar module is off or missing */
    public function enabledRegistrarModule(string $key): RegistrarModule
    {
        $plugin = $this->owner($key, 'registrar');

        if (! $plugin || ! $this->enabled($plugin)) {
            throw new ProvisioningException($this->offMessage($plugin, $key));
        }

        return $this->registrarModule($key);
    }

    /** @return list<string> Settings keys of every plugin (their pages are hidden while off, the values stay) */
    public function settingKeys(): array
    {
        return $this->all()->flatMap(fn (Plugin $plugin) => $plugin->settingKeys())->values()->all();
    }

    /** @return list<string> */
    public function encryptedSettingKeys(): array
    {
        return $this->all()->flatMap(fn (Plugin $plugin) => $plugin->encryptedSettingKeys())->values()->all();
    }

    /** @return array<string, string> */
    public function moduleLogs(): array
    {
        return $this->all()->flatMap(fn (Plugin $plugin) => $plugin->moduleLogs())->all();
    }

    /** @return list<class-string> */
    public function pages(): array
    {
        return $this->all()->flatMap(fn (Plugin $plugin) => $plugin->pages())->values()->all();
    }

    /** The first enabled plugin's reason for this client to pay no tax (Plugin::taxExemption()), else null. */
    public function taxExemption(Client $client): ?string
    {
        foreach ($this->enabledPlugins() as $plugin) {
            if (($reason = $plugin->taxExemption($client)) !== null) {
                return $reason;
            }
        }

        return null;
    }

    /** @return list<Action> */
    public function clientVatNumberActions(): array
    {
        return $this->all()->flatMap(fn (Plugin $plugin) => $plugin->clientVatNumberActions())->values()->all();
    }

    /** Every plugin's client area routes (Plugin::clientRoutes()), from the client panel. */
    public function clientRoutes(): void
    {
        foreach ($this->plugins as $plugin) {
            $plugin->clientRoutes();
        }
    }

    /** Every plugin's webhook routes (Plugin::webhookRoutes()), from routes/webhooks.php. */
    public function webhookRoutes(): void
    {
        foreach ($this->plugins as $plugin) {
            $plugin->webhookRoutes();
        }
    }

    /** @return list<Action> the enabled plugins' buttons for a payment notice in the client area */
    public function clientPaymentNoticeActions(): array
    {
        return $this->enabledPlugins()->flatMap(fn (Plugin $plugin) => $plugin->clientPaymentNoticeActions())->values()->all();
    }

    /** @return list<Htmlable> the enabled plugins' sign-in buttons for the client area's login and sign up pages */
    public function clientLoginButtons(): array
    {
        return $this->all()->filter(fn (Plugin $plugin) => $this->enabled($plugin))
            ->flatMap(fn (Plugin $plugin) => $plugin->clientLoginButtons())->values()->all();
    }

    /** @return list<class-string> */
    public function commands(): array
    {
        return $this->all()->flatMap(fn (Plugin $plugin) => $plugin->commands())->values()->all();
    }

    /** Every plugin's scheduled commands, on or off: each command does nothing while its plugin is off. */
    public function schedule(Schedule $schedule): void
    {
        $this->all()->each(fn (Plugin $plugin) => $plugin->schedule($schedule));
    }

    /** Merges each plugin's config.php into config, values already there winning (like mergeConfigFrom). */
    public function registerConfig(Application $app): void
    {
        if ($app instanceof CachesConfiguration && $app->configurationIsCached()) {
            return;
        }

        foreach ($this->plugins as $plugin) {
            if (is_file($file = $plugin->path().'/config.php')) {
                foreach (require $file as $key => $values) {
                    config([$key => array_replace_recursive($values, (array) config($key, []))]);
                }
            }
        }
    }

    /** Adds each plugin's lang/<code>.json; lang/ of the app wins where both have a text. */
    public function registerTranslations(Translator $translator): void
    {
        foreach ($this->plugins as $plugin) {
            if (is_dir($dir = $plugin->path().'/lang')) {
                $translator->addJsonPath($dir);
            }
            // Translations uploaded under Settings → Languages, over the plugin's own; kept when the plugin updates
            $translator->addJsonPath(Translations::pluginOverridePath($plugin));
        }

        // The core's uploaded translations, over lang/<code>.json; in storage, so an update keeps them
        $translator->addPath(Translations::overridePath());
    }

    /** Adds each plugin's views/ folder as the view namespace `<plugin id>::` (e.g. email_registrar::request). */
    public function registerViews(ViewFactory $views): void
    {
        foreach ($this->plugins as $plugin) {
            if (is_dir($dir = $plugin->path().'/views')) {
                $views->addNamespace($plugin->id(), $dir);
            }
        }
    }

    public function enable(Plugin $plugin): void
    {
        $this->switch($plugin, true);
    }

    public function disable(Plugin $plugin): void
    {
        $this->switch($plugin, false);
    }

    private function switch(Plugin $plugin, bool $enabled): void
    {
        // A plugin that needs a newer GaiaCP stays off whatever its switch says.
        if ($this->switchedOn($plugin) === $enabled || ($enabled && (! $this->compatible($plugin) || Demo::keepsPluginOff($plugin->id())))) {
            return;
        }

        // The version is only written for a new row; recordVersions() owns changes to it.
        DB::table('plugins')->upsert(
            [['id' => $plugin->id(), 'enabled' => $enabled, 'version' => $plugin->version(), 'version_changed_at' => now(), 'created_at' => now(), 'updated_at' => now()]],
            ['id'],
            ['enabled', 'updated_at'],
        );
        $this->rows = null;
        ActivityLog::record($enabled ? 'plugin.enabled' : 'plugin.disabled', "Plugin {$plugin->name()} ".($enabled ? 'enabled' : 'disabled'), properties: ['plugin' => $plugin->id()]);
    }

    private function offMessage(?Plugin $plugin, string $label): string
    {
        return match (true) {
            $plugin === null => __('No plugin handles :module.', ['module' => $label]),
            ! $this->compatible($plugin) => __('The :plugin plugin needs GaiaCP :version or later. Update GaiaCP under Settings → Updates.', ['plugin' => $plugin->name(), 'version' => $plugin->requires()]),
            default => __('The :plugin plugin is disabled. Enable it under Settings → Plugins.', ['plugin' => $plugin->name()]),
        };
    }

    /** @return array<string, object> id => row, for plugins that were switched or recorded */
    private function rows(): array
    {
        if ($this->rows !== null) {
            return $this->rows;
        }

        try {
            return $this->rows = DB::table('plugins')->get()->keyBy('id')->all();
        } catch (QueryException|SQLiteDatabaseDoesNotExistException) {
            return []; // no plugins table yet; read again next time
        }
    }
}
