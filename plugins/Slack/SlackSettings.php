<?php

namespace Plugins\Slack;

use App\Chat\ChatSettingsPage;
use BackedEnum;
use Filament\Support\Icons\Heroicon;

/** Settings → Slack: the channel's incoming webhook and the events posted there. */
class SlackSettings extends ChatSettingsPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static ?int $navigationSort = 62;

    protected static ?string $title = 'Slack';

    protected static ?string $slug = 'slack';

    public static function pluginId(): string
    {
        return 'slack';
    }

    public static function settingsDescription(): string
    {
        return __('Post new orders, payments, tickets and server problems to a Slack channel.');
    }

    protected static function howTo(): string
    {
        return __('In Slack, create an app at api.slack.com/apps, turn on Incoming Webhooks, add one for the channel and copy its URL (https://hooks.slack.com/services/…) here.');
    }
}
