<?php

namespace Plugins\Telegram;

use App\Chat\ChatSender;
use App\Events\GaiaEvent;
use App\Filament\Clusters\Settings\PluginSwitch;
use App\Filament\Clusters\Settings\SettingsFormPage;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use RuntimeException;
use UnitEnum;

/**
 * Settings → Telegram: the bot's token (encrypted, never sent back; blank keeps it), its chat, the events posted there
 * and Commands. Saving with Commands on points the bot's webhook here (setWebhook with a secret) and fills its menu;
 * turning it off removes the webhook. Super admins only, like Slack.
 */
class TelegramSettings extends SettingsFormPage
{
    use PluginSwitch;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPaperAirplane;

    protected static string|UnitEnum|null $navigationGroup = 'Email & notifications';

    protected static ?int $navigationSort = 64;

    protected static ?string $title = 'Telegram';

    protected static ?string $slug = 'telegram';

    public static function pluginId(): string
    {
        return 'telegram';
    }

    public static function settingsDescription(): string
    {
        return __('A Telegram bot that tells you about new orders, payments, tickets and server problems, and answers who owes.');
    }

    public static function keys(): array
    {
        return (new TelegramPlugin)->settingKeys();
    }

    /** The form's token and chat, saved or not (a blank token = the saved one), into config for a call now. */
    private function useTypedValues(): void
    {
        $state = data_get($this->form->getRawState(), 'telegram', []);

        if (filled($state['bot_token'] ?? null)) {
            config(['gaiacp.telegram.bot_token' => trim((string) $state['bot_token'])]);
        }

        if (filled($state['chat_id'] ?? null)) {
            config(['gaiacp.telegram.chat_id' => trim((string) $state['chat_id'])]);
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('test')
                ->label('Send a test message')
                ->icon(Heroicon::OutlinedPaperAirplane)
                ->color('gray')
                ->action(function () {
                    $this->useTypedValues();
                    $plugin = new TelegramPlugin;

                    if ($plugin->webhookUrl() === null) {
                        Notification::make()->warning()->title(__('Fill in the bot token and the chat ID first'))->send();

                        return;
                    }

                    $error = app(ChatSender::class)->test($plugin, $plugin->webhookUrl());

                    $error === null
                        ? Notification::make()->success()->title(__('Test message sent'))->send()
                        : Notification::make()->danger()->title(__('The test message was not sent'))->body(str_replace((string) config('gaiacp.telegram.bot_token'), '…', $error))->send();
                }),
        ];
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make(__('Bot'))
                    ->description(__('In Telegram, talk to @BotFather, send /newbot and copy the token it gives you here. Then send any message to your new bot (or add it to a group of admins and write there) and press Find chat ID.'))
                    ->schema([
                        TextInput::make('telegram.bot_token')
                            ->label('Bot token')
                            ->password()
                            ->revealable()
                            ->autocomplete('new-password')
                            ->maxLength(255)
                            ->placeholder(fn () => filled(config('gaiacp.telegram.bot_token')) ? '••••••••' : '123456:ABC…')
                            ->helperText(__('Stored encrypted. Leave blank to keep the saved one.')),
                        TextInput::make('telegram.chat_id')
                            ->label('Chat ID')
                            ->maxLength(64)
                            ->regex('/^-?\d+$/')
                            ->helperText(__('Where the messages go, and the only chat the bot answers.')),
                        Actions::make([
                            Action::make('findChat')
                                ->label('Find chat ID')
                                ->icon(Heroicon::OutlinedMagnifyingGlass)
                                ->color('gray')
                                ->action(fn () => $this->findChat()),
                        ])->key('chatActions'),
                    ]),
                Section::make(__('Messages'))
                    ->schema([
                        CheckboxList::make('telegram.events')
                            ->label('Post these events')
                            ->options(fn () => GaiaEvent::options())
                            ->columns(2)
                            ->bulkToggleable()
                            ->helperText(__('Each one becomes a short message with a link to its page here, within a minute.')),
                        Toggle::make('telegram.commands')
                            ->label('Answer commands')
                            ->helperText(__('In that chat, /owed lists who owes (overdue payment notices), /today today\'s payments, orders and tickets, /tickets those waiting for an answer and /servers their status. Read only.')),
                    ]),
            ]);
    }

    /** The chat of the newest message the bot got (getUpdates), into the form. */
    private function findChat(): void
    {
        $this->useTypedValues();

        try {
            $updates = TelegramApi::configured()->call('getUpdates', ['allowed_updates' => ['message']]);
        } catch (RuntimeException $e) {
            // While Commands is on Telegram keeps the messages for the webhook: the bot tells the chat its ID instead
            Notification::make()->warning()->title(__('Chat not found'))
                ->body(config('gaiacp.telegram.commands') ? __('Send /start to the bot: it answers with the chat\'s ID.') : $e->getMessage())->send();

            return;
        }

        $chat = collect(is_array($updates) ? $updates : [])->map(fn ($update) => data_get($update, 'message.chat'))->filter()->last();

        if ($chat === null) {
            Notification::make()->warning()->title(__('Chat not found'))->body(__('Send a message to the bot first, then press Find chat ID again.'))->send();

            return;
        }

        $this->data['telegram']['chat_id'] = (string) $chat['id'];
        Notification::make()->success()->title(__('Chat found: :name', ['name' => $chat['title'] ?? trim(($chat['first_name'] ?? '').' '.($chat['last_name'] ?? '')) ?: $chat['id']]))
            ->body(__('Save to use it.'))->send();
    }

    protected function forStorage(array $values): array
    {
        $values['telegram.events'] = array_values(array_intersect((array) ($values['telegram.events'] ?? []), GaiaEvent::NAMES));
        $values['telegram.commands'] = (bool) ($values['telegram.commands'] ?? false);

        return $values;
    }

    /** Commands on: Telegram posts the bot's messages here; off: it stops. */
    protected function afterSaving(array $values, array $previous): void
    {
        $on = (bool) config('gaiacp.telegram.commands');

        if (! $on && ! ($previous['telegram.commands'] ?? false)) {
            return;
        }

        $api = TelegramApi::configured();

        try {
            if ($on) {
                $api->call('setWebhook', [
                    'url' => route('webhooks.telegram'),
                    'secret_token' => TelegramApi::webhookSecret((string) config('gaiacp.telegram.bot_token')),
                    'allowed_updates' => ['message'],
                ]);
                $api->call('setMyCommands', ['commands' => collect(TelegramCommands::list())
                    ->map(fn (string $what, string $command) => ['command' => $command, 'description' => $what])->values()->all()]);
            } else {
                $api->call('deleteWebhook');
            }
        } catch (RuntimeException $e) {
            Notification::make()->danger()->persistent()->title(__('Telegram did not take the commands setting'))->body($e->getMessage())->send();
        }
    }
}
