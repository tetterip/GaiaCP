<?php

namespace App\Chat;

use App\Events\GaiaEvent;
use App\Models\ChatMessage;
use App\Plugins\Plugin;

/**
 * A plugin that posts GaiaCP's events to a chat service through its incoming webhook (Slack, Discord). Its settings
 * are `<id>.webhook_url` (encrypted) and `<id>.events` (GaiaEvent names); each event it wants is queued as a
 * ChatMessage and sent by gaiacp:send-chat-messages (ChatSender), so no action waits for the chat service.
 */
abstract class ChatPlugin extends Plugin
{
    /** The JSON the service's webhook takes for one message. @return array<string, mixed> */
    abstract public function message(ChatLine $line): array;

    /** The start every webhook URL of this service has (e.g. https://hooks.slack.com/), checked when it is saved. */
    abstract public function urlPrefixes(): array;

    public function webhookUrl(): ?string
    {
        $url = config("gaiacp.{$this->id()}.webhook_url");

        return filled($url) ? (string) $url : null;
    }

    public function events(): array
    {
        return array_values(array_intersect((array) config("gaiacp.{$this->id()}.events", []), GaiaEvent::NAMES));
    }

    public function handleEvent(GaiaEvent $event): void
    {
        if ($this->webhookUrl() === null) {
            return;
        }

        // In the app's language (config app.locale), not that of whoever caused it: a channel is shared
        $previous = app()->getLocale();
        app()->setLocale((string) config('app.locale'));

        try {
            $body = json_encode($this->message(ChatLine::from($event)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } finally {
            app()->setLocale($previous);
        }

        ChatMessage::query()->create(['channel' => $this->id(), 'event' => $event->name, 'body' => $body, 'next_attempt_at' => now()]);
    }

    public function settingKeys(): array
    {
        return ["{$this->id()}.webhook_url", "{$this->id()}.events"];
    }

    public function encryptedSettingKeys(): array
    {
        return ["{$this->id()}.webhook_url"];
    }

    public function moduleLogs(): array
    {
        return [$this->id() => $this->name()];
    }

    public function validUrl(string $url): bool
    {
        foreach ($this->urlPrefixes() as $prefix) {
            if (str_starts_with($url, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
