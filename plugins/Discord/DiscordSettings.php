<?php

namespace Plugins\Discord;

use App\Chat\ChatSettingsPage;
use BackedEnum;
use Filament\Support\Icons\Heroicon;

/** Settings → Discord: the channel's webhook and the events posted there. */
class DiscordSettings extends ChatSettingsPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleOvalLeftEllipsis;

    protected static ?int $navigationSort = 63;

    protected static ?string $title = 'Discord';

    protected static ?string $slug = 'discord';

    public static function pluginId(): string
    {
        return 'discord';
    }

    public static function settingsDescription(): string
    {
        return __('Post new orders, payments, tickets and server problems to a Discord channel.');
    }

    protected static function howTo(): string
    {
        return __('In Discord, open the channel\'s settings → Integrations → Webhooks → New Webhook, then Copy Webhook URL (https://discord.com/api/webhooks/…) and paste it here.');
    }
}
