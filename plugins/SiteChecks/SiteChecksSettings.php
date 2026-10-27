<?php

namespace Plugins\SiteChecks;

use App\Filament\Clusters\Settings\PluginSwitch;
use App\Filament\Clusters\Settings\SettingsFormPage;
use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/** When a certificate counts as expiring. The results are on Services → Site checks. */
class SiteChecksSettings extends SettingsFormPage
{
    use PluginSwitch;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Integrations';

    protected static ?int $navigationSort = 74;

    protected static ?string $title = 'Site checks';

    protected static ?string $slug = 'site-checks';

    public static function settingsDescription(): string
    {
        return __('Checks every day that each active hosting account\'s domain still points to its server and that its HTTPS certificate is valid and not about to expire, and tells you in the bell.');
    }

    public static function pluginId(): string
    {
        return 'site-checks';
    }

    public static function keys(): array
    {
        return (new SiteChecksPlugin)->settingKeys();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make(__('HTTPS certificates'))
                    ->description(__('Every day at 05:00 GaiaCP checks each active hosting account\'s domain. The results are under Services → Site checks; a new problem is in the bell of the admins who open services, once.'))
                    ->schema([
                        TextInput::make('site_checks.ssl_warn_days')
                            ->label('Warn before expiry')
                            ->suffix(__('days'))
                            ->integer()
                            ->minValue(1)
                            ->maxValue(90)
                            ->required()
                            ->helperText(__('Let\'s Encrypt renews 30 days ahead, so fewer days left usually means its renewal is failing.')),
                    ]),
            ]);
    }

    protected function forStorage(array $values): array
    {
        $values['site_checks.ssl_warn_days'] = (int) $values['site_checks.ssl_warn_days'];

        return $values;
    }
}
