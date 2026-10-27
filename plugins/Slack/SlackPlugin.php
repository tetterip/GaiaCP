<?php

namespace Plugins\Slack;

use App\Chat\ChatLine;
use App\Chat\ChatPlugin;

/** GaiaCP's events as messages in a Slack channel (an incoming webhook). */
class SlackPlugin extends ChatPlugin
{
    public function id(): string
    {
        return 'slack';
    }

    public function name(): string
    {
        return 'Slack';
    }

    public function description(): string
    {
        return __('Posts GaiaCP\'s events (new orders, payments, tickets, servers down) to a Slack channel.');
    }

    public function category(): string
    {
        return 'Integrations';
    }

    public function settingsPage(): ?string
    {
        return SlackSettings::class;
    }

    public function pages(): array
    {
        return [SlackSettings::class];
    }

    public function urlPrefixes(): array
    {
        return ['https://hooks.slack.com/'];
    }

    /** Slack's mrkdwn: the title in bold, the details and a link under it; `text` alone is the notification's preview. */
    public function message(ChatLine $line): array
    {
        $escape = fn (string $text) => str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $text);
        $link = $line->url ? "\n<{$line->url}|".$escape(__('Open in GaiaCP')).'>' : '';

        return [
            'text' => "{$line->emoji} {$line->title}: {$line->text}",
            'blocks' => [[
                'type' => 'section',
                'text' => ['type' => 'mrkdwn', 'text' => "{$line->emoji} *".$escape($line->title)."*\n".$escape($line->text).$link],
            ]],
        ];
    }
}
