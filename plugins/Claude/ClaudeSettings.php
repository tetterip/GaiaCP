<?php

namespace Plugins\Claude;

use App\Ai\AiException;
use App\Filament\Clusters\Settings\Pages\AiSettings;
use App\Filament\Clusters\Settings\PluginSwitch;
use App\Filament\Clusters\Settings\SettingsFormPage;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;
use UnitEnum;

/** Settings → Claude: the API key (encrypted, never sent back; blank keeps it) and the model. Super admins only. */
class ClaudeSettings extends SettingsFormPage
{
    use PluginSwitch;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static string|UnitEnum|null $navigationGroup = 'AI assistant';

    protected static ?int $navigationSort = 81;

    protected static ?string $title = 'Claude';

    protected static ?string $slug = 'claude';

    public static function pluginId(): string
    {
        return 'claude';
    }

    public static function settingsDescription(): string
    {
        return __('Claude, Anthropic\'s AI, for the AI assistant: it improves and translates texts you write, with your own API key.');
    }

    public static function keys(): array
    {
        return (new ClaudePlugin)->settingKeys();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('testConnection')
                ->label('Test connection')
                ->icon(Heroicon::OutlinedSignal)
                ->color('gray')
                ->action(function () {
                    // The key and model on the form, saved or not (a blank key = the saved one)
                    $state = data_get($this->form->getRawState(), 'claude', []);
                    if (filled($state['api_key'] ?? null)) {
                        config(['gaiacp.claude.api_key' => trim((string) $state['api_key'])]);
                    }
                    if (filled($state['model'] ?? null)) {
                        config(['gaiacp.claude.model' => $state['model']]);
                    }

                    try {
                        $name = (new ClaudePlugin)->testConnection();
                    } catch (AiException $e) {
                        Notification::make()->danger()->title(__('Connection failed'))->body($e->getMessage())->persistent()->send();

                        return;
                    }

                    Notification::make()->success()->title(__('Connected: :model', ['model' => $name]))->send();
                }),
        ];
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make(__('Connection'))
                    ->description(new HtmlString(__('Create an API key at <strong>console.anthropic.com</strong> (Settings → API keys) and paste it here. Anthropic bills your account for what is used; set a monthly limit there and under Settings → AI assistant. Texts are sent only when an admin presses an AI button, and by default Anthropic does not use API data to train its models.')))
                    ->schema([
                        TextInput::make('claude.api_key')
                            ->label('API key')
                            ->password()
                            ->revealable()
                            ->autocomplete('new-password')
                            ->placeholder(fn () => filled(config('gaiacp.claude.api_key')) ? '••••••••' : 'sk-ant-…')
                            ->helperText(__('Stored encrypted. Leave blank to keep the saved one.'))
                            ->maxLength(255),
                        Select::make('claude.model')
                            ->label('Model')
                            ->options(ClaudePlugin::MODELS)
                            ->selectablePlaceholder(false)
                            ->required()
                            ->helperText(__('Opus writes best; Sonnet is half the price; Haiku is the cheapest and fastest, good enough for spelling and translations.')),
                        Text::make(fn () => new HtmlString(__('What the assistant may do, and a monthly limit: <a href=":url" class="underline">Settings → AI assistant</a>.', ['url' => AiSettings::getUrl()]))),
                    ]),
            ]);
    }
}
