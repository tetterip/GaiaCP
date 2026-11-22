<?php

namespace Tests\Support\TestChat;

use App\Chat\ChatLine;
use App\Chat\ChatPlugin;

/** A chat plugin (App\Chat) for GaiaCP's own tests: Slack, Discord and Telegram are in the plugin catalogue. */
class TestChatPlugin extends ChatPlugin
{
    public const URL = 'https://chat.example.com/hooks/abc';

    public function id(): string
    {
        return 'test-chat';
    }

    public function name(): string
    {
        return 'Test chat';
    }

    public function description(): string
    {
        return 'The chat service GaiaCP\'s own tests use.';
    }

    public function category(): string
    {
        return 'Integrations';
    }

    public function urlPrefixes(): array
    {
        return ['https://chat.example.com/'];
    }

    public function message(ChatLine $line): array
    {
        return ['text' => "{$line->emoji} {$line->title}: {$line->text}", 'url' => $line->url];
    }
}
