<?php

namespace Plugins\UptimeKuma;

use App\Filament\Clusters\Settings\PluginSwitch;
use App\Filament\Clusters\Settings\SettingsFormPage;
use App\Monitoring\MonitoringException;
use App\Monitoring\MonitorStatus;
use App\Monitoring\ServerMonitoring;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;
use UnitEnum;

/** Where Uptime Kuma is and its API key. Super admins only, like Servers. Each server picks its monitor on its own form. */
class UptimeKumaSettings extends SettingsFormPage
{
    use PluginSwitch;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSignal;

    protected static string|UnitEnum|null $navigationGroup = 'Integrations';

    protected static ?int $navigationSort = 70;

    protected static ?string $title = 'Uptime Kuma';

    protected static ?string $slug = 'uptime-kuma';

    public static function settingsDescription(): string
    {
        return __('Shows whether each server is up from its Uptime Kuma monitor, and tells you in the bell when one goes down.');
    }

    public static function pluginId(): string
    {
        return 'uptime_kuma';
    }

    public static function keys(): array
    {
        return (new UptimeKumaPlugin)->settingKeys();
    }

    protected function forForm(array $values): array
    {
        $values['uptime_kuma.verify_tls'] = (bool) ($values['uptime_kuma.verify_tls'] ?? true);

        return $values;
    }

    protected function afterSaving(array $values, array $previous): void
    {
        app(ServerMonitoring::class)->forget(new UptimeKumaPlugin); // the server form's picker reads it again
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('testConnection')
                ->label('Test connection')
                ->icon(Heroicon::OutlinedSignal)
                ->color('gray')
                ->action(function () {
                    // What is on the form, saved or not (a blank key = the saved one).
                    $form = $this->form->getRawState();
                    config([
                        'gaiacp.uptime_kuma.url' => data_get($form, 'uptime_kuma.url'),
                        'gaiacp.uptime_kuma.username' => data_get($form, 'uptime_kuma.username'),
                        'gaiacp.uptime_kuma.verify_tls' => (bool) data_get($form, 'uptime_kuma.verify_tls', true),
                    ]);
                    if (filled($key = data_get($form, 'uptime_kuma.password'))) {
                        config(['gaiacp.uptime_kuma.password' => $key]);
                    }

                    try {
                        $monitors = collect((new UptimeKumaPlugin)->monitors());
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
                    ->description(new HtmlString(__('GaiaCP reads the status of every monitor from Uptime Kuma\'s <code>/metrics</code> page every 5 minutes. In Uptime Kuma open <strong>Settings → API Keys</strong>, press <strong>Generate</strong> and paste the key here. Then link each server to its monitor on the server\'s form (Settings → Servers).')))
                    ->columns(2)
                    ->schema([
                        TextInput::make('uptime_kuma.url')
                            ->label('Uptime Kuma address')
                            ->url()
                            ->placeholder('https://status.example.com')
                            ->autocomplete('off')
                            ->columnSpanFull()
                            ->maxLength(255),
                        TextInput::make('uptime_kuma.password')
                            ->label('API key / password')
                            ->password()
                            ->revealable()
                            ->autocomplete('new-password')
                            ->placeholder(fn () => filled(config('gaiacp.uptime_kuma.password')) ? '••••••••' : 'uk1_…')
                            ->helperText(__('Stored encrypted. Leave blank to keep the saved one.'))
                            ->maxLength(255),
                        TextInput::make('uptime_kuma.username')
                            ->label('Username')
                            ->autocomplete('off')
                            ->helperText(__('Leave empty with an API key. Only for an older Uptime Kuma without API keys: its login, with the password in the field next to it.'))
                            ->maxLength(255),
                        Toggle::make('uptime_kuma.verify_tls')
                            ->label('Verify TLS certificate')
                            ->default(true)
                            ->helperText(__('Turn off only for a server still on a self-signed certificate.')),
                        Text::make(__('Paused monitors are not on the metrics page, so their servers show Unknown.'))
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
