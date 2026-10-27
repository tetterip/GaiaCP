<?php

namespace App\Filament\Clusters\Settings\Pages;

use App\Captcha\Captcha;
use App\Enums\AdminArea;
use App\Filament\Clusters\Settings\SettingsFormPage;
use App\Support\Region;
use App\Themes\Themes;
use BackedEnum;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Symfony\Component\Intl\Countries;
use UnitEnum;

/**
 * Settings that belong to no one area, first on the Settings page: the region (timezone, date format, the country new
 * clients start in), when payment notices are created (was Settings →
 * Billing), the admin panel's default theme and the captcha on the login forms. Admins with business settings open it; the captcha is for super
 * admins only.
 */
class GeneralSettings extends SettingsFormPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static string|UnitEnum|null $navigationGroup = 'General';

    protected static ?int $navigationSort = 10;

    protected static ?string $title = 'General';

    protected static ?string $slug = 'general';

    public static function adminArea(): ?AdminArea
    {
        return AdminArea::BusinessSettings;
    }

    public static function settingsDescription(): string
    {
        return __('Your timezone, date format and country, when payment notices are created, the admin panel\'s default theme, and the captcha on the login, sign up and forgot password forms.');
    }

    public static function keys(): array
    {
        return ['region.timezone', 'region.date_format', 'region.country', 'invoices.days_before', 'theme.default', 'captcha.provider', 'captcha.forms'];
    }

    private static function editsCaptcha(): bool
    {
        return (bool) auth()->user()?->super_admin;
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make(__('Region'))
                    ->columns(3)
                    ->schema([
                        Select::make('region.timezone')
                            ->label('Timezone')
                            ->options(fn () => Region::timezoneOptions())
                            ->searchable()
                            ->required()
                            ->in(fn () => array_keys(Region::timezoneOptions()))
                            ->helperText(__('Due dates, the daily run and every time shown. Times already saved keep their clock time.')),
                        Select::make('region.date_format')
                            ->label('Date format')
                            ->options(fn () => Region::dateFormatOptions())
                            ->selectablePlaceholder(false)
                            ->required()
                            ->in(Region::DATE_FORMATS),
                        Select::make('region.country')
                            ->label('Country')
                            ->options(fn () => Countries::getNames(app()->getLocale()))
                            ->searchable()
                            ->placeholder(__('None'))
                            ->helperText(__('New clients start in it, and phone numbers without a country code are taken as its.')),
                    ]),
                Section::make(__('Payment notices'))
                    ->schema([
                        TextInput::make('invoices.days_before')
                            ->label('Create payment notices')
                            ->suffix(__('days before the due date'))
                            ->integer()
                            ->required()
                            ->minValue(0)
                            ->maxValue(90)
                            ->helperText(__('One notice (proforma, not a legal invoice) per client and due date, emailed when it is created. Overdue services without one get it on the next daily run.')),
                    ]),
                Section::make(__('Admin panel'))
                    ->schema([
                        Select::make('theme.default')
                            ->label('Default theme')
                            ->options(fn () => Themes::options())
                            ->placeholder(__('None'))
                            ->selectablePlaceholder(false)
                            ->helperText(__('For admins who haven\'t picked their own in Profile. Each theme is a plugin (Settings → Plugins).')),
                    ]),
                Section::make(__('Captcha'))
                    ->visible(fn () => self::editsCaptcha())
                    ->description(__('A check that the visitor is not a robot, against bots guessing passwords or signing up. Each captcha is a plugin (Settings → Plugins) with its own keys; one shows here once its keys are saved.'))
                    ->schema([
                        Select::make('captcha.provider')
                            ->label('Captcha')
                            ->options(fn () => Captcha::options())
                            ->placeholder(__('None'))
                            ->live()
                            ->helperText(fn () => Captcha::options() === []
                                ? __('No captcha is set up yet: enter the keys under Settings → Google reCAPTCHA or Settings → Cloudflare Turnstile first.')
                                : null),
                        CheckboxList::make('captcha.forms')
                            ->label('Show it on')
                            ->options(fn () => Captcha::formLabels())
                            ->visible(fn (Get $get) => filled($get('captcha.provider')))
                            ->required(fn (Get $get) => filled($get('captcha.provider'))),
                    ]),
            ]);
    }

    protected function forStorage(array $values): array
    {
        $values['invoices.days_before'] = (int) $values['invoices.days_before'];
        $values['region.country'] = filled($values['region.country'] ?? null) ? strtoupper($values['region.country']) : null;

        if (! self::editsCaptcha()) { // the section was hidden: keep what is set
            $values['captcha.provider'] = config('gaiacp.captcha.provider');
            $values['captcha.forms'] = (array) config('gaiacp.captcha.forms');

            return $values;
        }

        $forms = array_values(array_intersect(Captcha::FORMS, (array) ($values['captcha.forms'] ?? [])));
        // With no captcha the list is hidden: keep the forms ticked before
        $values['captcha.forms'] = $forms !== [] ? $forms : (array) config('gaiacp.captcha.forms');

        return $values;
    }
}
