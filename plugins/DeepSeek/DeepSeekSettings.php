<?php

namespace Plugins\DeepSeek;

use App\Ai\ChatCompletionsSettings;

/** Settings → DeepSeek: the API key and the model. */
class DeepSeekSettings extends ChatCompletionsSettings
{
    protected static ?int $navigationSort = 82;

    protected static ?string $title = 'DeepSeek';

    protected static ?string $slug = 'deepseek';

    public static function pluginId(): string
    {
        return 'deepseek';
    }

    public static function settingsDescription(): string
    {
        return __('DeepSeek for the AI assistant: it improves and translates texts you write, with your own API key.');
    }

    protected static function howTo(): string
    {
        return __('Create an API key at <strong>platform.deepseek.com</strong> (API keys), add some balance and paste the key here.');
    }
}
