<?php

namespace Plugins\Discord;

use App\Chat\ChatLine;
use App\Chat\ChatPlugin;

/** GaiaCP's events as messages in a Discord channel (a channel webhook). */
class DiscordPlugin extends ChatPlugin
{
    private const COLORS = ['info' => 0x2C7A7C, 'success' => 0x2F7D52, 'warning' => 0xD97706, 'danger' => 0xDC2626];

    public function id(): string
    {
        return 'discord';
    }

    public function name(): string
    {
        return 'Discord';
    }

    public function description(): string
    {
        return __('Posts GaiaCP\'s events (new orders, payments, tickets, servers down) to a Discord channel.');
    }

    public function category(): string
    {
        return 'Integrations';
    }

    public function settingsPage(): ?string
    {
        return DiscordSettings::class;
    }

    public function pages(): array
    {
        return [DiscordSettings::class];
    }

    public function urlPrefixes(): array
    {
        return ['https://discord.com/api/webhooks/', 'https://discordapp.com/api/webhooks/', 'https://ptb.discord.com/api/webhooks/', 'https://canary.discord.com/api/webhooks/'];
    }

    /** One embed: the title (linked to its page here), the details and a colour for how good or bad it is. Mentions are off. */
    public function message(ChatLine $line): array
    {
        return [
            'username' => 'GaiaCP',
            'allowed_mentions' => ['parse' => []],
            'embeds' => [array_filter([
                'title' => mb_substr("{$line->emoji} {$line->title}", 0, 256),
                'description' => mb_substr($line->text, 0, 4000),
                'url' => $line->url,
                'color' => self::COLORS[$line->level] ?? self::COLORS['info'],
            ], fn ($value) => $value !== null)],
        ];
    }
}
