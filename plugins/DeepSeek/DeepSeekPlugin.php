<?php

namespace Plugins\DeepSeek;

use App\Ai\ChatCompletionsPlugin;

/** DeepSeek for the AI assistant: its Chat Completions API with the admin's own API key. */
class DeepSeekPlugin extends ChatCompletionsPlugin
{
    public function id(): string
    {
        return 'deepseek';
    }

    public function name(): string
    {
        return 'DeepSeek';
    }

    public function description(): string
    {
        return __('DeepSeek for the AI assistant: it improves and translates texts you write, with your own API key.');
    }

    public function baseUrl(): string
    {
        return (string) config('gaiacp.deepseek.base_url') ?: 'https://api.deepseek.com';
    }

    public function suggestedModels(): array
    {
        return ['deepseek-chat', 'deepseek-flash', 'deepseek-v4-pro', 'deepseek-reasoner'];
    }

    public function settingsPage(): ?string
    {
        return DeepSeekSettings::class;
    }

    public function pages(): array
    {
        return [DeepSeekSettings::class];
    }
}
