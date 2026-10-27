<?php

namespace Plugins\OpenAi;

use App\Ai\ChatCompletionsSettings;

/** Settings → ChatGPT: the API key and the model. */
class OpenAiSettings extends ChatCompletionsSettings
{
    protected static ?int $navigationSort = 84;

    protected static ?string $title = 'ChatGPT';

    protected static ?string $slug = 'openai';

    public static function pluginId(): string
    {
        return 'openai';
    }

    public static function settingsDescription(): string
    {
        return __('ChatGPT, OpenAI\'s models, for the AI assistant: it improves and translates texts you write, with your own API key.');
    }

    protected static function howTo(): string
    {
        return __('Create an API key on OpenAI\'s platform (<strong>platform.openai.com</strong>, API keys), add some credit under Billing and paste the key here. A ChatGPT Plus subscription does not include API use.');
    }
}
