<?php

namespace Plugins\UptimeRobot;

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

/** UptimeRobot's API key. Super admins only, like Servers. Each server picks its monitor on its own form. */
class UptimeRobotSettings extends SettingsFormPage
{
    use PluginSwitch;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSignal;

    protected static string|UnitEnum|null $navigationGroup = 'Integrations';

    protected static ?int $navigationSort = 71;

    protected static ?string $title = 'UptimeRobot';

    protected static ?string $slug = 'uptime-robot';

    public static function settingsDescription(): string
    {
        return __('Shows whether each server is up from its UptimeRobot monitor, and tells you in the bell when one goes down.');
    }

    public static function pluginId(): string
    {
        return 'uptime_robot';
    }

    public static function keys(): array
    {
        return (new UptimeRobotPlugin)->settingKeys();
    }

    protected function afterSaving(array $values, array $previous): void
    {
        app(ServerMonitoring::class)->forget(new UptimeRobotPlugin); // the server form's picker reads it again
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('testConnection')
                ->label('Test connection')
                ->icon(Heroicon::OutlinedSignal)
                ->color('gray')
                ->action(function () {
                    // The key on the form, saved or not (blank = the saved one).
                    if (filled($key = data_get($this->form->getRawState(), 'uptime_robot.api_key'))) {
                        config(['gaiacp.uptime_robot.api_key' => $key]);
                    }

                    try {
                        $monitors = collect((new UptimeRobotPlugin)->monitors());
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
                    ->description(new HtmlString(__('GaiaCP reads the status and uptime of every monitor from UptimeRobot\'s API every 5 minutes; the Free plan has the API too. In UptimeRobot open <strong>Integrations & API</strong> (<strong>My Settings</strong> on older accounts), create a <strong>Read-Only API Key</strong> and paste it here. Then link each server to its monitor on the server\'s form (Settings → Servers).')))
                    ->schema([
                        TextInput::make('uptime_robot.api_key')
                            ->label('Read-Only API key')
                            ->password()
                            ->revealable()
                            ->autocomplete('new-password')
                            ->placeholder(fn () => filled(config('gaiacp.uptime_robot.api_key')) ? '••••••••' : 'ur…')
                            ->helperText(__('Stored encrypted. Leave blank to keep the saved one. The main API key works too, but the read-only one can change nothing.'))
                            ->maxLength(255),
                        Text::make(__('Paused monitors show Unknown, and a monitor UptimeRobot has not confirmed down yet shows Pending.')),
                    ]),
            ]);
    }
}
