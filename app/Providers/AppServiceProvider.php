<?php

namespace App\Providers;

use App\Models\ApiKey;
use App\Notifications\ResetAdminPassword;
use App\Plugins\PluginManager;
use App\Support\ClientFields;
use App\Support\Currencies;
use App\Support\Demo;
use App\Support\Locales;
use App\Support\Region;
use App\Support\Settings;
use App\Support\Translations;
use App\Support\Version;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Auth\Notifications\ResetPassword as FilamentResetPassword;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Field;
use Filament\Infolists\Components\Entry;
use Filament\Pages\BasePage;
use Filament\Tables\Columns\Column;
use Filament\Tables\Filters\BaseFilter;
use Filament\Tables\Table;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Database\Events\NoPendingMigrations;
use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /** How dates are written everywhere: Settings → General → Date format (App\Support\Region). */
    public static function dateFormat(): string
    {
        return Region::dateFormat();
    }

    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Building the demo's data (App\Support\DemoSnapshot): this process migrates and seeds that SQLite file, not
        // the live database, before anything reads it. Read from the process's own environment, so a cached config can't hide it.
        if (is_string($building = getenv('GAIACP_DEMO_BUILD')) && $building !== '') {
            config([
                'database.default' => 'sqlite',
                'database.connections.sqlite.database' => $building,
                'gaiacp.demo.enabled' => true,
            ]);
        }

        // Plugins (plugins/*): their config.php is merged here, before anything reads config.
        $this->app->singleton(PluginManager::class);
        $this->app->scoped(Currencies::class); // Settings → Currencies, read once per request
        $this->app->scoped(Translations::class); // the languages found, read once per request
        $this->app->scoped(ClientFields::class); // Settings → Client fields, read once per request
        $this->app->make(PluginManager::class)->registerConfig($this->app);

        // "Forgot password?" mail: sent at once (Filament's own is queued) in the admin's language
        $this->app->bind(FilamentResetPassword::class, fn ($app, array $parameters) => new ResetAdminPassword($parameters['token']));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Save / Create stay at the foot of the screen on long forms (styled by filament/sticky-actions), on the right
        // with Save rightmost, like the Settings pages' Save bar
        BasePage::stickyFormActions();
        BasePage::alignFormActionsEnd();

        // REST API: calls per minute for each key (by IP before a key is known)
        RateLimiter::for('gaiacp-api', fn (Request $request) => Limit::perMinute(max(1, (int) config('gaiacp.api.per_minute', 120)))
            ->by(ApiKey::current() ? 'key:'.ApiKey::current()->id : 'ip:'.$request->ip()));

        $plugins = $this->app->make(PluginManager::class);
        $plugins->registerTranslations($this->app->make('translator'));
        $plugins->registerViews($this->app->make('view'));
        $this->commands($plugins->commands());
        $this->loadMigrationsFrom($plugins->migrationPaths());

        // Each plugin's installed version (`plugins.version`), noted after every migrate: deploys, gaiacp:update, install
        Event::listen([MigrationsEnded::class, NoPendingMigrations::class], function (MigrationsEnded|NoPendingMigrations $event) use ($plugins) {
            if ($event->method === 'up' && ! ($event instanceof MigrationsEnded && ($event->options['pretend'] ?? false))) {
                $plugins->recordVersions();
            }
        });

        Settings::apply();

        // The public demo: no email or HTTP call leaves the server (App\Support\Demo)
        if (Demo::enabled()) {
            Demo::boot();
        }

        // `php artisan about` names the GaiaCP version (the VERSION file)
        AboutCommand::add('GaiaCP', fn (): array => ['Version' => Version::current(), 'Stage' => Version::STAGE ?? 'stable']);

        // The default language (first of gaiacp.locales) for the daily jobs and the login page; the
        // panel switches to the admin's own (SetLocale) and emails to the client's (ClientMail).
        app()->setLocale(Locales::default());
        app('translator')->setFallback(Locales::default());

        // Labels go through __(), written ones and the ones Filament makes from field names alike, so a
        // language file translates them without wrapping each ->label() (App\Support\Locales).
        foreach ([Field::class, Entry::class, Column::class, BaseFilter::class, Action::class, ActionGroup::class] as $component) {
            $component::configureUsing(fn ($c) => $c->translateLabel());
        }

        Table::configureUsing(fn (Table $table) => $table
            ->defaultDateDisplayFormat(self::dateFormat())
            ->defaultDateTimeDisplayFormat(self::dateFormat().' H:i'));

        DatePicker::configureUsing(fn (DatePicker $picker) => $picker
            ->native(false)
            ->displayFormat(self::dateFormat())
            ->firstDayOfWeek(1));
    }
}
