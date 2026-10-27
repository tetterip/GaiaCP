<?php

namespace Plugins\Turnstile;

use App\Filament\Clusters\Settings\SettingsFormPage;
use App\Plugins\PluginManager;
use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;
use UnitEnum;

/** The Cloudflare Turnstile keys. Super admins only, like Settings → General where the captcha is picked. */
class TurnstileSettings extends SettingsFormPage
{
    public const CONSOLE = 'https://dash.cloudflare.com/?to=/:account/turnstile';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Client area';

    protected static ?int $navigationSort = 43;

    protected static ?string $title = 'Cloudflare Turnstile';

    protected static ?string $slug = 'turnstile';

    public static function settingsDescription(): string
    {
        return __('Cloudflare\'s privacy-friendly check that a visitor is not a robot, on the login and sign up forms.');
    }

    /** Hidden while the plugin is disabled (Settings → Plugins). */
    public static function canAccess(): bool
    {
        return app(PluginManager::class)->enabled('turnstile') && parent::canAccess();
    }

    public static function keys(): array
    {
        return (new TurnstilePlugin)->settingKeys();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make(__('Keys'))
                    ->schema([
                        Text::make(new HtmlString(__(
                            'In the <a href=":url" target="_blank" rel="noopener" class="underline">Cloudflare dashboard → Turnstile</a> press <strong>Add widget</strong>, add your domains (e.g. :host) and choose the <strong>Managed</strong> mode. Paste the site key and secret key it gives you here, then pick Cloudflare Turnstile under Settings → General.',
                            ['url' => self::CONSOLE, 'host' => e(request()->getHost())],
                        ))),
                        TextInput::make('turnstile.site_key')
                            ->label('Site key')
                            ->autocomplete('off')
                            ->maxLength(255),
                        TextInput::make('turnstile.secret_key')
                            ->label('Secret key')
                            ->password()
                            ->revealable()
                            ->autocomplete('new-password')
                            ->placeholder(fn () => filled(config('gaiacp.turnstile.secret_key')) ? '••••••••' : null)
                            ->helperText(__('Stored encrypted. Leave blank to keep the saved one.'))
                            ->required(fn (Get $get) => filled($get('turnstile.site_key')) && blank(config('gaiacp.turnstile.secret_key')))
                            ->maxLength(255),
                    ]),
            ]);
    }
}
