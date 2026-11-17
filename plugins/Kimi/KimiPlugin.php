<?php

namespace Plugins\Kimi;

use App\Ai\ChatCompletionsPlugin;

/** Kimi (Moonshot AI) for the AI assistant: its Chat Completions API with the admin's own API key. */
class KimiPlugin extends ChatCompletionsPlugin
{
    public function id(): string
    {
        return 'kimi';
    }

    public function name(): string
    {
        return 'Kimi';
    }

    public function description(): string
    {
        return __('Kimi, Moonshot AI\'s assistant, for the AI assistant: it improves and translates texts you write, with your own API key.');
    }

    /** The international platform; an account on Moonshot's China platform uses https://api.moonshot.cn/v1 (GAIACP_KIMI_BASE_URL). */
    public function baseUrl(): string
    {
        return (string) config('gaiacp.kimi.base_url') ?: 'https://api.moonshot.ai/v1';
    }

    public function suggestedModels(): array
    {
        return ['kimi-k2.6', 'kimi-k3'];
    }

    public function settingsPage(): ?string
    {
        return KimiSettings::class;
    }

    public function pages(): array
    {
        return [KimiSettings::class];
    }
}
