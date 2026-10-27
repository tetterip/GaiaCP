<?php

namespace Plugins\Recaptcha;

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

/** The Google reCAPTCHA keys. Super admins only, like Settings → General where the captcha is picked. */
class RecaptchaSettings extends SettingsFormPage
{
    public const CONSOLE = 'https://www.google.com/recaptcha/admin/create';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Client area';

    protected static ?int $navigationSort = 42;

    protected static ?string $title = 'Google reCAPTCHA';

    protected static ?string $slug = 'recaptcha';

    public static function settingsDescription(): string
    {
        return __('Google\'s "I\'m not a robot" check on the login and sign up forms.');
    }

    /** Hidden while the plugin is disabled (Settings → Plugins). */
    public static function canAccess(): bool
    {
        return app(PluginManager::class)->enabled('recaptcha') && parent::canAccess();
    }

    public static function keys(): array
    {
        return (new RecaptchaPlugin)->settingKeys();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make(__('Keys'))
                    ->schema([
                        Text::make(new HtmlString(__(
                            'At <a href=":url" target="_blank" rel="noopener" class="underline">Google reCAPTCHA → Create</a> choose <strong>reCAPTCHA v2</strong>, <strong>"I\'m not a robot" Checkbox</strong>, and add your domains (e.g. :host). Paste the site key and secret key it gives you here, then pick Google reCAPTCHA under Settings → General.',
                            ['url' => self::CONSOLE, 'host' => e(request()->getHost())],
                        ))),
                        TextInput::make('recaptcha.site_key')
                            ->label('Site key')
                            ->autocomplete('off')
                            ->maxLength(255),
                        TextInput::make('recaptcha.secret_key')
                            ->label('Secret key')
                            ->password()
                            ->revealable()
                            ->autocomplete('new-password')
                            ->placeholder(fn () => filled(config('gaiacp.recaptcha.secret_key')) ? '••••••••' : null)
                            ->helperText(__('Stored encrypted. Leave blank to keep the saved one.'))
                            ->required(fn (Get $get) => filled($get('recaptcha.site_key')) && blank(config('gaiacp.recaptcha.secret_key')))
                            ->maxLength(255),
                    ]),
            ]);
    }
}
