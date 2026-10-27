<?php

namespace App\Chat;

use App\Models\ActivityLog;
use App\Models\ChatMessage;
use App\Plugins\PluginManager;
use App\Support\ModuleLogger;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Posts the queued chat messages to their plugin's webhook (gaiacp:send-chat-messages, every minute). A 2xx answer is
 * sent; anything else is tried again after 1, 5 and 30 minutes, then the message failed (logged `chat.failed`). A
 * message whose plugin is off or has no webhook any more fails at once.
 */
class ChatSender
{
    public const RETRY_MINUTES = [1, 5, 30];

    private const PER_RUN = 30;

    public function __construct(private PluginManager $plugins) {}

    /** @return array{sent: int, failed: int} */
    public function sendDue(): array
    {
        $summary = ['sent' => 0, 'failed' => 0];

        $due = ChatMessage::query()
            ->where('status', ChatMessage::PENDING)
            ->where('next_attempt_at', '<=', now())
            ->orderBy('id')
            ->limit(self::PER_RUN)
            ->get();

        foreach ($due as $message) {
            $plugin = $this->plugins->find($message->channel);
            $url = $plugin instanceof ChatPlugin && $this->plugins->enabled($plugin) ? $plugin->webhookUrl() : null;

            if ($url === null) {
                $message->update(['status' => ChatMessage::FAILED, 'next_attempt_at' => null, 'error' => 'The plugin is off or has no webhook URL']);
                $summary['failed']++;

                continue;
            }

            $error = $this->post($plugin, $url, $message->body);
            $attempts = $message->attempts + 1;
            $retry = $error !== null && $attempts <= count(self::RETRY_MINUTES);

            $message->update([
                'status' => $error === null ? ChatMessage::SENT : ($retry ? ChatMessage::PENDING : ChatMessage::FAILED),
                'attempts' => $attempts,
                'next_attempt_at' => $retry ? now()->addMinutes(self::RETRY_MINUTES[$attempts - 1]) : null,
                'error' => $error,
            ]);

            if ($error === null) {
                $summary['sent']++;
            } elseif (! $retry) {
                $summary['failed']++;
                ActivityLog::record('chat.failed', "{$plugin->name()} message for {$message->event} not sent after {$attempts} tries: {$error}", properties: ['channel' => $message->channel, 'message_id' => $message->id]);
            }
        }

        return $summary;
    }

    /** Sends a sample message now; null when it was posted, else why not. */
    public function test(ChatPlugin $plugin, string $url): ?string
    {
        return $this->post($plugin, $url, json_encode($plugin->message(ChatLine::test()), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /** Deletes sent and failed messages older than Settings → Logging keeps module log entries. */
    public function prune(): int
    {
        return ChatMessage::query()
            ->whereIn('status', [ChatMessage::SENT, ChatMessage::FAILED])
            ->where('created_at', '<', now()->subDays(max(1, (int) config('gaiacp.debug.module_log_days')))->startOfDay())
            ->delete();
    }

    private function post(ChatPlugin $plugin, string $url, string $body): ?string
    {
        try {
            $response = Http::timeout(10)
                ->connectTimeout(5)
                ->withoutRedirecting()
                ->withMiddleware(ModuleLogger::middleware($plugin->id(), 'message', [$url]))
                ->withBody($body, 'application/json')
                ->post($url);

            return $response->successful() ? null : Str::limit("HTTP {$response->status()}: ".trim($response->body()), 480);
        } catch (Throwable $e) {
            return Str::limit($e->getMessage(), 480);
        }
    }
}
