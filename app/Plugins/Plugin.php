<?php

namespace App\Plugins;

use App\Ai\AiModule;
use App\Captcha\CaptchaModule;
use App\Certificates\CertificateModule;
use App\Events\GaiaEvent;
use App\Models\Client;
use App\Monitoring\MonitoringModule;
use App\Payments\PaymentModule;
use App\Provisioning\RegistrarModule;
use App\Provisioning\ServerModule;
use App\Themes\ThemeModule;
use Filament\Actions\Action;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Support\Htmlable;
use ReflectionClass;

/**
 * A plugin adds one capability to GaiaCP (a server panel, a registrar, an integration) on top of the core billing.
 * It lives in its own folder under plugins/ with its code, plugin.json (its version and the oldest GaiaCP it runs
 * on), CHANGELOG.md (its versions, newest first), an optional config.php (merged into config, existing values win)
 * and lang/<code>.json (its own translations) and database/migrations (run by migrate). Listed under Settings → Plugins (PluginManager),
 * where it is enabled or disabled; disabling keeps its data. The plugins bundled with GaiaCP are always installed
 * and can't be uninstalled; others are uploaded there as a zip (PluginInstaller) and can be.
 */
abstract class Plugin
{
    /** Stable id, stored in `plugins.id`. */
    abstract public function id(): string;

    abstract public function name(): string;

    /** One line: what it adds. Translated. */
    abstract public function description(): string;

    /** Plugins page group: Servers, Registrars, Monitoring, Payments, Integrations or Themes (Pages\Plugins::categoryLabel()). */
    abstract public function category(): string;

    /**
     * Whether a bundled plugin starts switched on in a new install (or when it first appears after an update). False
     * for the ones only some countries or sellers use (ΑΑΔΕ, easy.gr); a plugin added by upload always starts off.
     */
    public function enabledByDefault(): bool
    {
        return true;
    }

    /** @var array<string, mixed>|null plugin.json, read once */
    private ?array $manifest = null;

    /** Its version (plugin.json "version", x.y.z). PluginManager::recordVersions() notes when it changes. */
    public function version(): string
    {
        return (string) ($this->manifest()['version'] ?? '1.0.0');
    }

    /** The oldest GaiaCP version it runs on (plugin.json "requires"); null = any. */
    public function requires(): ?string
    {
        $requires = $this->manifest()['requires'] ?? null;

        return filled($requires) ? (string) $requires : null;
    }

    /** CHANGELOG.md of its folder (Markdown), or null when it has none. */
    public function changelog(): ?string
    {
        $file = $this->path().'/CHANGELOG.md';

        return is_file($file) ? (string) file_get_contents($file) : null;
    }

    /** Reads plugin.json again (after an update replaced the folder). */
    public function refreshManifest(): void
    {
        $this->manifest = null;
    }

    /** Its migrations folder, run by `migrate` with the app's own (and on install). */
    public function migrationPath(): string
    {
        return $this->path().'/database/migrations';
    }

    /** @return array<string, mixed> */
    private function manifest(): array
    {
        if ($this->manifest === null) {
            $file = $this->path().'/plugin.json';
            $this->manifest = is_file($file) ? (array) json_decode((string) file_get_contents($file), true) : [];
        }

        return $this->manifest;
    }

    /** The plugin's folder. */
    public function path(): string
    {
        return dirname((string) (new ReflectionClass($this))->getFileName());
    }

    /** @return list<ServerModule> the plugin itself when it is a server module */
    public function serverModules(): array
    {
        return $this instanceof ServerModule ? [$this] : [];
    }

    /** @return list<RegistrarModule> the plugin itself when it is a registrar module */
    public function registrarModules(): array
    {
        return $this instanceof RegistrarModule ? [$this] : [];
    }

    /** @return list<CertificateModule> the plugin itself when it sells SSL certificates */
    public function certificateModules(): array
    {
        return $this instanceof CertificateModule ? [$this] : [];
    }

    /** @return list<PaymentModule> the plugin itself when it is a payment method */
    public function paymentModules(): array
    {
        return $this instanceof PaymentModule ? [$this] : [];
    }

    /** Whether clients pay through it online (a payment gateway): never pre-selected when an admin records a payment. */
    public function paysOnline(): bool
    {
        return false;
    }

    /** @return list<CaptchaModule> the plugin itself when it is a captcha (Settings → General picks one) */
    public function captchaModules(): array
    {
        return $this instanceof CaptchaModule ? [$this] : [];
    }

    /** @return list<MonitoringModule> the plugin itself when it is an uptime monitoring service (a server links to one of its monitors) */
    public function monitoringModules(): array
    {
        return $this instanceof MonitoringModule ? [$this] : [];
    }

    /** @return list<AiModule> the plugin itself when it is an AI service (Settings → AI assistant uses one) */
    public function aiModules(): array
    {
        return $this instanceof AiModule ? [$this] : [];
    }

    /** @return list<ThemeModule> the plugin itself when it is an admin panel theme (Profile and Settings → General pick one) */
    public function themeModules(): array
    {
        return $this instanceof ThemeModule ? [$this] : [];
    }

    /** @return class-string|null the settings page that belongs to it, hidden while it is off */
    public function settingsPage(): ?string
    {
        return null;
    }

    /** @return list<class-string> Filament pages it adds to the panel (they check enabled() in canAccess()) */
    public function pages(): array
    {
        return [];
    }

    /** @return list<class-string> Artisan commands it adds */
    public function commands(): array
    {
        return [];
    }

    /** Puts its commands on the schedule (routes/console.php); a command checks itself whether the plugin is on. */
    public function schedule(Schedule $schedule): void {}

    /**
     * @return list<string> the App\Events\GaiaEvent names it wants in handleEvent() (GaiaEvent::NAMES), or
     *                      ['*'] for all of them
     */
    public function events(): array
    {
        return [];
    }

    /**
     * Called (only while it is on) for each event of events(), after the action that raised it is committed and
     * while the request or command is still running, so anything slow (an HTTP call) belongs on the scheduler.
     * An exception here is reported and never undoes the action.
     */
    public function handleEvent(GaiaEvent $event): void {}

    /** @return list<string> keys under config('gaiacp.*') its settings page stores (App\Support\Settings) */
    public function settingKeys(): array
    {
        return [];
    }

    /** @return list<string> those of settingKeys() stored encrypted */
    public function encryptedSettingKeys(): array
    {
        return [];
    }

    /** @return array<string, string> ModuleLogger module key => label, for the module log */
    public function moduleLogs(): array
    {
        return [];
    }

    /**
     * Routes it adds to the client area, outside its login (named filament.client.*, with the panel's middleware).
     * Registered whether the plugin is on or off (states are read later), so its controllers check enabled().
     */
    public function clientRoutes(): void {}

    /**
     * Routes outside services call (payment webhooks), under /webhooks and named webhooks.*: no session, cookies or
     * CSRF token. Registered whether the plugin is on or off, so its controllers check enabled().
     */
    public function webhookRoutes(): void {}

    /** @return list<Action> buttons on a payment notice in the client area, e.g. pay online (only while it is on) */
    public function clientPaymentNoticeActions(): array
    {
        return [];
    }

    /** @return list<Htmlable> sign-in buttons under the client area's login and sign up forms (only while it is on) */
    public function clientLoginButtons(): array
    {
        return [];
    }

    /**
     * Why this client pays no tax although a Settings → Tax rule applies to them (e.g. "Reverse charge"), printed on
     * their payment notices; null = no opinion. Asked only while the plugin is on (App\Support\Tax::ruleFor()).
     */
    public function taxExemption(Client $client): ?string
    {
        return null;
    }

    /** @return list<Action> buttons next to the VAT number on the client form (hide them while the plugin is off) */
    public function clientVatNumberActions(): array
    {
        return [];
    }
}
