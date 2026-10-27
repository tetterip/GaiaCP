<?php

namespace App\Chat;

use App\Events\GaiaEvent;
use App\Filament\Clusters\Settings\PluginSwitch;
use App\Filament\Clusters\Settings\SettingsFormPage;
use App\Plugins\PluginManager;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * A chat plugin's settings page (Slack, Discord): its On switch, the webhook URL (encrypted, never sent back; blank
 * keeps it), the events it posts and a Test button. Super admins only, since messages carry client names.
 */
abstract class ChatSettingsPage extends SettingsFormPage
{
    use PluginSwitch;

    protected static string|UnitEnum|null $navigationGroup = 'Email & notifications';

    /** How to get a webhook URL in the service, one or two sentences. */
    abstract protected static function howTo(): string;

    public static function keys(): array
    {
        return [static::pluginId().'.webhook_url', static::pluginId().'.events'];
    }

    protected static function plugin(): ChatPlugin
    {
        /** @var ChatPlugin */
        return app(PluginManager::class)->find(static::pluginId());
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('test')
                ->label('Send a test message')
                ->icon(Heroicon::OutlinedPaperAirplane)
                ->color('gray')
                ->action(function () {
                    // The URL typed in, saved or not (blank = the saved one)
                    $url = trim((string) data_get($this->form->getRawState(), static::pluginId().'.webhook_url')) ?: static::plugin()->webhookUrl();

                    if ($url === null || ! static::plugin()->validUrl($url)) {
                        Notification::make()->warning()->title(__('Enter the webhook URL first'))->send();

                        return;
                    }

                    $error = app(ChatSender::class)->test(static::plugin(), $url);

                    $error === null
                        ? Notification::make()->success()->title(__('Test message sent'))->send()
                        : Notification::make()->danger()->title(__('The test message was not sent'))->body($error)->send();
                }),
        ];
    }

    public function form(Schema $schema): Schema
    {
        $id = static::pluginId();

        return $schema
            ->statePath('data')
            ->components([
                Section::make(__('Channel'))
                    ->description(static::howTo())
                    ->schema([
                        TextInput::make("{$id}.webhook_url")
                            ->label('Webhook URL')
                            ->password()
                            ->revealable()
                            ->autocomplete('new-password')
                            ->maxLength(500)
                            ->placeholder(fn () => static::plugin()->webhookUrl() ? '••••••••' : static::plugin()->urlPrefixes()[0].'…')
                            ->rule(fn () => function (string $attribute, mixed $value, Closure $fail) {
                                if (filled($value) && ! static::plugin()->validUrl(trim((string) $value))) {
                                    $fail(__('This is not a :service webhook URL.', ['service' => static::plugin()->name()]));
                                }
                            })
                            ->helperText(__('Stored encrypted. Leave blank to keep the saved one.')),
                        CheckboxList::make("{$id}.events")
                            ->label('Post these events')
                            ->options(fn () => GaiaEvent::options())
                            ->columns(2)
                            ->bulkToggleable()
                            ->helperText(__('Each one becomes a short message with a link to its page here, within a minute.')),
                    ]),
            ]);
    }

    protected function forStorage(array $values): array
    {
        $key = static::pluginId().'.events';
        $values[$key] = array_values(array_intersect((array) ($values[$key] ?? []), GaiaEvent::NAMES));

        return $values;
    }
}
