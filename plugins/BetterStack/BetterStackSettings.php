<?php

namespace Plugins\BetterStack;

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

/** Better Stack's Uptime API token. Super admins only, like Servers. Each server picks its monitor on its own form. */
class BetterStackSettings extends SettingsFormPage
{
    use PluginSwitch;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSignal;

    protected static string|UnitEnum|null $navigationGroup = 'Integrations';

    protected static ?int $navigationSort = 73;

    protected static ?string $title = 'Better Stack';

    protected static ?string $slug = 'better-stack';

    public static function settingsDescription(): string
    {
        return __('Shows whether each server is up from its Better Stack Uptime monitor, and tells you in the bell when one goes down.');
    }

    public static function pluginId(): string
    {
        return 'better_stack';
    }

    public static function keys(): array
    {
        return (new BetterStackPlugin)->settingKeys();
    }

    protected function afterSaving(array $values, array $previous): void
    {
        app(ServerMonitoring::class)->forget(new BetterStackPlugin); // the server form's picker reads it again
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
                    if (filled($token = data_get($this->form->getRawState(), 'better_stack.api_token'))) {
                        config(['gaiacp.better_stack.api_token' => $token]);
                    }

                    try {
                        $monitors = collect((new BetterStackPlugin)->monitors());
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
                    ->description(new HtmlString(__('GaiaCP reads the status of every monitor from Better Stack Uptime\'s API (v2) every 5 minutes; the Free plan has the API too. In Better Stack open <strong>Settings → API tokens</strong>, copy your team\'s Uptime API token and paste it here. Then link each server to its monitor on the server\'s form (Settings → Servers).')))
                    ->schema([
                        TextInput::make('better_stack.api_token')
                            ->label('API token')
                            ->password()
                            ->revealable()
                            ->autocomplete('new-password')
                            ->placeholder(fn () => filled(config('gaiacp.better_stack.api_token')) ? '••••••••' : '')
                            ->helperText(__('Stored encrypted. Leave blank to keep the saved one. GaiaCP only reads with it.'))
                            ->maxLength(255),
                        Text::make(__('Paused monitors show Unknown, and monitors in maintenance show Maintenance.')),
                    ]),
            ]);
    }
}
