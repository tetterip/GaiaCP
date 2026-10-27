<?php

namespace Plugins\Telegram;

use App\Support\ModuleLogger;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/** Calls to the Telegram Bot API (https://api.telegram.org/bot<token>/<method>) for the settings page. */
class TelegramApi
{
    public const BASE = 'https://api.telegram.org/bot';

    public function __construct(private string $token) {}

    public static function configured(): self
    {
        return new self(trim((string) config('gaiacp.telegram.bot_token')));
    }

    /**
     * @param  array<string, mixed>  $params
     * @return mixed the answer's `result`
     *
     * @throws RuntimeException with Telegram's description when the call fails
     */
    public function call(string $method, array $params = []): mixed
    {
        if ($this->token === '') {
            throw new RuntimeException(__('Fill in the bot token first.'));
        }

        try {
            $response = Http::timeout(15)
                ->connectTimeout(5)
                ->acceptJson()
                ->withMiddleware(ModuleLogger::middleware('telegram', $method, [$this->token]))
                ->post(self::BASE.$this->token.'/'.$method, $params);
        } catch (Throwable $e) {
            throw new RuntimeException(__('Could not reach Telegram: :error', ['error' => str_replace($this->token, '…', $e->getMessage())]), previous: $e);
        }

        $body = $response->json();

        if (! is_array($body) || ($body['ok'] ?? false) !== true) {
            throw new RuntimeException(__('Telegram answered: :error', ['error' => is_array($body) ? (string) ($body['description'] ?? "HTTP {$response->status()}") : "HTTP {$response->status()}"]));
        }

        return $body['result'] ?? null;
    }

    /** The secret Telegram sends back with each update (X-Telegram-Bot-Api-Secret-Token), made from the token. */
    public static function webhookSecret(string $token): string
    {
        return hash_hmac('sha256', "telegram-webhook|{$token}", (string) config('app.key'));
    }
}
