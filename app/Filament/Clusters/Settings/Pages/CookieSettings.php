<?php

namespace App\Filament\Clusters\Settings\Pages;

use App\Filament\Clusters\Settings\SettingsFormPage;
use App\Support\CookieConsent;
use BackedEnum;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * The cookie banner of the client area (App\Support\CookieConsent). Super admins only, since the scripts it adds run
 * on every client's page.
 */
class CookieSettings extends SettingsFormPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFingerPrint;

    protected static string|UnitEnum|null $navigationGroup = 'Client area';

    protected static ?int $navigationSort = 44;

    protected static ?string $title = 'Cookies';

    protected static ?string $slug = 'cookies';

    public static function settingsDescription(): string
    {
        return __('A cookie banner in the client area, a link to your cookie policy, and scripts such as analytics that run only after the visitor accepts.');
    }

    public static function keys(): array
    {
        return ['cookies.banner', 'cookies.text', 'cookies.policy_url', 'cookies.google_analytics', 'cookies.scripts'];
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make(__('Cookie banner'))
                    ->description(__('GaiaCP itself sets only the cookies it needs to work (signing in, forms). The banner shows at the foot of the client area, the public order page and sign up until the visitor chooses; "Cookie settings" at the foot of the page asks again.'))
                    ->schema([
                        Toggle::make('cookies.banner')
                            ->label('Show the cookie banner'),
                        Textarea::make('cookies.text')
                            ->label('Text')
                            ->rows(3)
                            ->maxLength(1000)
                            ->placeholder(fn () => __('We use the cookies this site needs to work, and, if you accept, cookies that help us understand how the site is used.'))
                            ->helperText(__('Empty: a built-in text in the visitor\'s language, which mentions other cookies only when you add scripts below.')),
                        TextInput::make('cookies.policy_url')
                            ->label('Link to the cookie policy')
                            ->url()
                            ->maxLength(500)
                            ->placeholder('https://')
                            ->helperText(__('A page on your website. Opens in a new tab.')),
                    ]),
                Section::make(__('Scripts after consent'))
                    ->description(__('E.g. the code of Google Analytics, a Meta pixel or a chat widget. They are added to the client area\'s pages only after the visitor presses "Accept all"; with scripts here the banner offers "Only necessary" and "Accept all" instead of OK.'))
                    ->schema([
                        TextInput::make('cookies.google_analytics')
                            ->label('Google Analytics measurement ID')
                            ->placeholder('G-XXXXXXXXXX')
                            ->maxLength(30)
                            ->regex(CookieConsent::GA_PATTERN)
                            ->dehydrateStateUsing(fn (?string $state) => filled($state) ? strtoupper(trim($state)) : null)
                            ->helperText(__('From Google Analytics → Admin → Data streams. GaiaCP adds Google\'s tag for it, so you don\'t paste its code below. Needs the cookie banner on: it counts only visitors who accept.')),
                        Textarea::make('cookies.scripts')
                            ->label('Scripts')
                            ->rows(8)
                            ->maxLength(20000)
                            ->extraInputAttributes(['style' => 'font-family: ui-monospace, monospace; font-size: .8125rem;'])
                            ->placeholder('<script async src="https://www.googletagmanager.com/gtag/js?id=G-XXXX"></script>')
                            ->helperText(__('HTML as the service gives it, <script> tags included. They run on every client\'s page, so paste only code you trust.')),
                    ]),
            ]);
    }

    protected function forStorage(array $values): array
    {
        $values['cookies.banner'] = (bool) ($values['cookies.banner'] ?? false);

        return $values;
    }
}
