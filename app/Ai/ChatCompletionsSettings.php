<?php

namespace App\Ai;

use App\Filament\Clusters\Settings\Pages\AiSettings;
use App\Filament\Clusters\Settings\PluginSwitch;
use App\Filament\Clusters\Settings\SettingsFormPage;
use App\Plugins\PluginManager;
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

/**
 * The settings page of a ChatCompletionsPlugin (DeepSeek, Kimi): its On switch, the API key (encrypted, never sent
 * back; blank keeps it), the model (suggested ones, or those Test connection read) and Test connection. Super
 * admins only.
 */
abstract class ChatCompletionsSettings extends SettingsFormPage
{
    use PluginSwitch;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static string|UnitEnum|null $navigationGroup = 'AI assistant';

    /** Where to get an API key, one sentence (may hold <strong>). */
    abstract protected static function howTo(): string;

    public static function keys(): array
    {
        return [static::pluginId().'.api_key', static::pluginId().'.model'];
    }

    protected static function plugin(): ChatCompletionsPlugin
    {
        /** @var ChatCompletionsPlugin */
        return app(PluginManager::class)->find(static::pluginId());
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('testConnection')
                ->label('Test connection')
                ->icon(Heroicon::OutlinedSignal)
                ->color('gray')
                ->action(function () {
                    $id = static::pluginId();
                    // The key and model on the form, saved or not (a blank key = the saved one)
                    $state = (array) data_get($this->form->getRawState(), $id, []);
                    if (filled($state['api_key'] ?? null)) {
                        config(["gaiacp.{$id}.api_key" => trim((string) $state['api_key'])]);
                    }
                    if (filled($state['model'] ?? null)) {
                        config(["gaiacp.{$id}.model" => trim((string) $state['model'])]);
                    }

                    try {
                        $models = static::plugin()->models();
                    } catch (AiException $e) {
                        Notification::make()->danger()->title(__('Connection failed'))->body($e->getMessage())->persistent()->send();

                        return;
                    }

                    $model = static::plugin()->model();

                    $models === [] || in_array($model, $models, true)
                        ? Notification::make()->success()->title(__('Connected: :model', ['model' => $model]))->send()
                        : Notification::make()->warning()->title(__('Connected, but this key has no model :model', ['model' => $model]))
                            ->body(__('Models it has: :models', ['models' => implode(', ', $models)]))->persistent()->send();
                }),
        ];
    }

    public function form(Schema $schema): Schema
    {
        $id = static::pluginId();

        return $schema
            ->statePath('data')
            ->components([
                Section::make(__('Connection'))
                    ->description(new HtmlString(static::howTo().' '.e(__(':service bills your account for what is used. Texts are sent only when an admin presses an AI button.', ['service' => static::plugin()->name()]))))
                    ->schema([
                        TextInput::make("{$id}.api_key")
                            ->label('API key')
                            ->password()
                            ->revealable()
                            ->autocomplete('new-password')
                            ->placeholder(fn () => filled(config("gaiacp.{$id}.api_key")) ? '••••••••' : 'sk-…')
                            ->helperText(__('Stored encrypted. Leave blank to keep the saved one.'))
                            ->maxLength(255),
                        TextInput::make("{$id}.model")
                            ->label('Model')
                            ->datalist(fn () => static::plugin()->knownModels())
                            ->placeholder(fn () => static::plugin()->suggestedModels()[0])
                            ->maxLength(100)
                            ->helperText(__('Empty: :model. Test connection lists the models your key can use, which are then offered here.', ['model' => static::plugin()->suggestedModels()[0]])),
                        Text::make(fn () => new HtmlString(__('What the assistant may do, and a monthly limit: <a href=":url" class="underline">Settings → AI assistant</a>.', ['url' => AiSettings::getUrl()]))),
                    ]),
            ]);
    }
}
