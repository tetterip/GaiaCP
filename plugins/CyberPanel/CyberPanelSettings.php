<?php

namespace Plugins\CyberPanel;

use App\Filament\Clusters\Settings\PluginSwitch;
use App\Filament\Clusters\Settings\SettingsFormPage;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/** What new CyberPanel websites start with. Super admins only, like Servers; each server is set up under Settings → Servers. */
class CyberPanelSettings extends SettingsFormPage
{
    use PluginSwitch;

    /** CyberPanel's names for the PHP versions it installs (lsphp74 … lsphp85). */
    public const PHP_VERSIONS = ['PHP 7.4', 'PHP 8.0', 'PHP 8.1', 'PHP 8.2', 'PHP 8.3', 'PHP 8.4', 'PHP 8.5'];

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedServerStack;

    protected static string|UnitEnum|null $navigationGroup = 'Integrations';

    protected static ?int $navigationSort = 66;

    protected static ?string $title = 'CyberPanel';

    protected static ?string $slug = 'cyberpanel';

    public static function settingsDescription(): string
    {
        return __('The PHP version new websites start on, on CyberPanel servers.');
    }

    public static function pluginId(): string
    {
        return 'cyberpanel';
    }

    public static function keys(): array
    {
        return (new CyberPanelPlugin)->settingKeys();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make(__('New websites'))
                    ->description(__('Add each CyberPanel server under Settings → Servers, with its admin user and password. A hosting account there is a website with an owner user of the same username.'))
                    ->schema([
                        Select::make('cyberpanel.php_version')
                            ->label('PHP version')
                            ->options(array_combine(self::PHP_VERSIONS, self::PHP_VERSIONS))
                            ->required()
                            ->selectablePlaceholder(false)
                            ->helperText(__('It must be installed on the server. The client can change it later in CyberPanel.')),
                    ]),
            ]);
    }
}
