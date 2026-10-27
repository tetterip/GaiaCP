<?php

namespace Plugins\Kimi;

use App\Ai\ChatCompletionsSettings;

/** Settings → Kimi: the API key and the model. */
class KimiSettings extends ChatCompletionsSettings
{
    protected static ?int $navigationSort = 83;

    protected static ?string $title = 'Kimi';

    protected static ?string $slug = 'kimi';

    public static function pluginId(): string
    {
        return 'kimi';
    }

    public static function settingsDescription(): string
    {
        return __('Kimi, Moonshot AI\'s assistant, for the AI assistant: it improves and translates texts you write, with your own API key.');
    }

    protected static function howTo(): string
    {
        return __('Create an API key on Moonshot AI\'s platform (<strong>platform.moonshot.ai</strong>, API keys), add some balance and paste the key here.');
    }
}
