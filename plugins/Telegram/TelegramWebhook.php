<?php

namespace Plugins\Telegram;

use App\Models\ActivityLog;
use App\Plugins\PluginManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * POST /webhooks/telegram: a message someone sent the bot (Telegram's webhook, set while Commands is on). Telegram
 * proves it is the caller with the secret made from the token. The admins' chat gets its answer in the reply itself
 * (a sendMessage), any other chat only its chat ID on /start, so the bot tells strangers nothing.
 */
class TelegramWebhook
{
    public function __invoke(Request $request, PluginManager $plugins, TelegramCommands $commands): JsonResponse
    {
        $token = trim((string) config('gaiacp.telegram.bot_token'));

        if (! $plugins->enabled('telegram') || ! config('gaiacp.telegram.commands') || $token === ''
            || ! hash_equals(TelegramApi::webhookSecret($token), (string) $request->header('X-Telegram-Bot-Api-Secret-Token'))) {
            return response()->json(['error' => 'Not found'], 404);
        }

        $chatId = data_get($request->input('message'), 'chat.id');
        $text = (string) data_get($request->input('message'), 'text', '');

        // Edits, joins, photos…: nothing to answer
        if ($chatId === null || ! str_starts_with($text, '/')) {
            return response()->json(['ok' => true]);
        }

        if ((string) $chatId !== trim((string) config('gaiacp.telegram.chat_id'))) {
            return str_starts_with(strtolower($text), '/start')
                ? response()->json(['method' => 'sendMessage'] + TelegramPlugin::send(e(__('This chat\'s ID is :id. To use the bot here, put it under Settings → Telegram → Chat ID.', ['id' => $chatId])), $chatId))
                : response()->json(['ok' => true]);
        }

        // In the app's language, as the event messages
        $previous = app()->getLocale();
        app()->setLocale((string) config('app.locale'));

        try {
            $answer = $commands->answer($text);
        } finally {
            app()->setLocale($previous);
        }

        ActivityLog::record('telegram.command', Str::limit('Telegram bot answered '.strtok($text, ' '), 250));

        return response()->json(['method' => 'sendMessage'] + TelegramPlugin::send($answer, $chatId));
    }
}
