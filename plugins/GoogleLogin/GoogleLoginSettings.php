<?php

namespace Plugins\GoogleLogin;

use App\Enums\AdminArea;
use App\Filament\Clusters\Settings\PluginSwitch;
use App\Filament\Clusters\Settings\SettingsFormPage;
use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;
use UnitEnum;

/** The Google OAuth client behind "Continue with Google" in the client area and, if ticked, on /admin's login. */
class GoogleLoginSettings extends SettingsFormPage
{
    use PluginSwitch;

    public const CONSOLE = 'https://console.cloud.google.com/apis/credentials';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static string|UnitEnum|null $navigationGroup = 'Client area';

    protected static ?int $navigationSort = 41;

    protected static ?string $title = 'Google sign-in';

    protected static ?string $slug = 'google-sign-in';

    public static function adminArea(): ?AdminArea
    {
        return AdminArea::ServiceSettings;
    }

    public static function settingsDescription(): string
    {
        return __('Clients log in or sign up in the client area with their Google account.');
    }

    public static function pluginId(): string
    {
        return 'google_login';
    }

    public static function keys(): array
    {
        return (new GoogleLoginPlugin)->settingKeys();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make(__('Google OAuth client'))
                    ->description(__('"Continue with Google" shows on the client area\'s login page once both are filled in. It logs in the client whose email address Google confirms; an address that is not a client here goes to the sign up form when Settings → Client area lets new clients sign up.'))
                    ->schema([
                        Text::make(new HtmlString(__(
                            'In <a href=":url" target="_blank" rel="noopener" class="underline">Google Cloud Console → APIs & Services → Credentials</a> '
                            .'press <strong>Create credentials → OAuth client ID</strong>, type <strong>Web application</strong>, and add the address below under '
                            .'<strong>Authorized redirect URIs</strong>. Google first asks you to set up the consent screen: your company name, support email and logo; '
                            .'the scopes needed are only email, profile and openid. Then paste the client ID and secret here.',
                            ['url' => self::CONSOLE],
                        ))),
                        TextInput::make('redirect_uri')
                            ->label('Authorized redirect URI')
                            ->default(fn () => GoogleOAuth::redirectUri())
                            ->formatStateUsing(fn () => GoogleOAuth::redirectUri())
                            ->readOnly()
                            ->copyable()
                            ->dehydrated(false),
                        TextInput::make('admin_redirect_uri')
                            ->label('Authorized redirect URI for admins')
                            ->default(fn () => GoogleOAuth::redirectUri('admin'))
                            ->formatStateUsing(fn () => GoogleOAuth::redirectUri('admin'))
                            ->helperText(__('Add this one too when admins log in with Google.'))
                            ->readOnly()
                            ->copyable()
                            ->dehydrated(false)
                            ->visible(fn (Get $get) => (bool) $get('google_login.admins')),
                        TextInput::make('google_login.client_id')
                            ->label('Client ID')
                            ->placeholder('1234567890-abc123.apps.googleusercontent.com')
                            ->autocomplete('off')
                            ->maxLength(255),
                        TextInput::make('google_login.client_secret')
                            ->label('Client secret')
                            ->password()
                            ->revealable()
                            ->autocomplete('new-password')
                            ->placeholder(fn () => filled(config('gaiacp.google_login.client_secret')) ? '••••••••' : null)
                            ->helperText(__('Stored encrypted. Leave blank to keep the saved one.'))
                            ->required(fn (Get $get) => filled($get('google_login.client_id')) && blank(config('gaiacp.google_login.client_secret')))
                            ->maxLength(255),
                    ]),
                Section::make(__('Admins'))
                    ->schema([
                        Toggle::make('google_login.admins')
                            ->label('Admins can log in to /admin with Google too')
                            ->helperText(__('Only an admin who can log in and has the same email address as the Google account; nobody new gets in. Two-factor authentication is still asked when the admin has it on.'))
                            ->live(),
                    ]),
            ]);
    }

    protected function forForm(array $values): array
    {
        $values['google_login.admins'] = (bool) ($values['google_login.admins'] ?? false);

        return $values;
    }

    protected function forStorage(array $values): array
    {
        $values['google_login.admins'] = (bool) ($values['google_login.admins'] ?? false);

        return $values;
    }
}
