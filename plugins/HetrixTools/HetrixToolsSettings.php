<?php

namespace Plugins\HetrixTools;

use App\Filament\Clusters\Settings\PluginSwitch;
use App\Filament\Clusters\Settings\SettingsFormPage;
use App\Monitoring\MonitoringException;
use App\Monitoring\MonitorStatus;
use App\Monitoring\ServerMonitoring;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;
use UnitEnum;

/** HetrixTools' API token. Super admins only, like Servers. Each server picks its monitor on its own form. */
class HetrixToolsSettings extends SettingsFormPage
{
    use PluginSwitch;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSignal;

    protected static string|UnitEnum|null $navigationGroup = 'Integrations';

    protected static ?int $navigationSort = 72;

    protected static ?string $title = 'HetrixTools';

    protected static ?string $slug = 'hetrix-tools';

    public static function settingsDescription(): string
    {
        return __('Shows whether each server is up from its HetrixTools uptime monitor, and tells you in the bell when one goes down.');
    }

    public static function pluginId(): string
    {
        return 'hetrix_tools';
    }

    public static function keys(): array
    {
        return (new HetrixToolsPlugin)->settingKeys();
    }

    protected function afterSaving(array $values, array $previous): void
    {
        app(ServerMonitoring::class)->forget(new HetrixToolsPlugin); // the server form's picker reads it again
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('testConnection')
                ->label('Test connection')
                ->icon(Heroicon::OutlinedSignal)
                ->color('gray')
                ->action(function () {
                    // The token on the form, saved or not (blank = the saved one).
                    if (filled($token = data_get($this->form->getRawState(), 'hetrix_tools.api_token'))) {
                        config(['gaiacp.hetrix_tools.api_token' => $token]);
                    }

                    try {
                        $monitors = collect((new HetrixToolsPlugin)->monitors());
                    } catch (MonitoringException $e) {
                        Notification::make()->danger()->title(__('Connection failed'))->body($e->getMessage())->persistent()->send();

                        return;
                    }

                    Notification::make()->success()
                        ->title(__('Connected: :count monitor(s)', ['count' => $monitors->count()]))
                        ->body(__(':up up, :down down', [
                            'up' => $monitors->where('status', MonitorStatus::Up)->count(),
                            'down' => $monitors->where('status', MonitorStatus::Down)->count(),
                        ]))
                        ->send();
                }),
        ];
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make(__('Connection'))
                    ->description(new HtmlString(__('GaiaCP reads the status and uptime of every uptime monitor from HetrixTools\' API (v3) every 5 minutes; the Free plan has the API too. In HetrixTools open <strong>Account → API Keys</strong>, create a key with read access to uptime monitors and paste it here. Then link each server to its monitor on the server\'s form (Settings → Servers).')))
                    ->schema([
                        TextInput::make('hetrix_tools.api_token')
                            ->label('API token')
                            ->password()
                            ->revealable()
                            ->autocomplete('new-password')
                            ->placeholder(fn () => filled(config('gaiacp.hetrix_tools.api_token')) ? '••••••••' : '')
                            ->helperText(__('Stored encrypted. Leave blank to keep the saved one. A key that can only read is enough.'))
                            ->maxLength(255),
                        Text::make(__('Paused monitors show Unknown, and monitors in maintenance show Maintenance.')),
                    ]),
            ]);
    }
}
