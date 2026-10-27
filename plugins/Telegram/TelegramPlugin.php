<?php

namespace Plugins\Telegram;

use App\Chat\ChatLine;
use App\Chat\ChatPlugin;
use Illuminate\Support\Facades\Route;

/**
 * GaiaCP's events as messages from a Telegram bot to one chat (an admin or a group of admins), sent like Slack's through
 * the chat outbox (sendMessage). With Commands on, Telegram posts that chat's messages to POST /webhooks/telegram and
 * the bot answers /owed, /today, /tickets and /servers (TelegramCommands); other chats get nothing but their chat ID.
 */
class TelegramPlugin extends ChatPlugin
{
    public function id(): string
    {
        return 'telegram';
    }

    public function name(): string
    {
        return 'Telegram';
    }

    public function description(): string
    {
        return __('A Telegram bot for admins: GaiaCP\'s events (new orders, payments, tickets, servers down) in a chat, and answers to /owed, /today, /tickets and /servers.');
    }

    public function category(): string
    {
        return 'Integrations';
    }

    public function settingsPage(): ?string
    {
        return TelegramSettings::class;
    }

    public function pages(): array
    {
        return [TelegramSettings::class];
    }

    public function webhookRoutes(): void
    {
        Route::post('telegram', TelegramWebhook::class)->middleware('throttle:120,1')->name('telegram');
    }

    /** The bot's sendMessage address, once both the token and the chat are set. */
    public function webhookUrl(): ?string
    {
        $token = trim((string) config('gaiacp.telegram.bot_token'));

        return $token !== '' && filled(config('gaiacp.telegram.chat_id')) ? TelegramApi::BASE.$token.'/sendMessage' : null;
    }

    public function urlPrefixes(): array
    {
        return [TelegramApi::BASE];
    }

    /** sendMessage to the chat: the title in bold, the details and a link, as Telegram's HTML. */
    public function message(ChatLine $line): array
    {
        $link = $line->url ? "\n<a href=\"".e($line->url).'">'.e(__('Open in GaiaCP')).'</a>' : '';

        return self::send("{$line->emoji} <b>".e($line->title)."</b>\n".e($line->text).$link);
    }

    /** @return array<string, mixed> sendMessage's parameters for an HTML text to the chat set in Settings */
    public static function send(string $html, int|string|null $chatId = null): array
    {
        return [
            'chat_id' => (string) ($chatId ?? config('gaiacp.telegram.chat_id')),
            'text' => mb_substr($html, 0, 4000),
            'parse_mode' => 'HTML',
            'link_preview_options' => ['is_disabled' => true],
        ];
    }

    public function settingKeys(): array
    {
        return ['telegram.bot_token', 'telegram.chat_id', 'telegram.events', 'telegram.commands'];
    }

    public function encryptedSettingKeys(): array
    {
        return ['telegram.bot_token'];
    }
}
