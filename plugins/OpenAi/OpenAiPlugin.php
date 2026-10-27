<?php

namespace Plugins\OpenAi;

use App\Ai\ChatCompletionsPlugin;

/** ChatGPT (OpenAI) for the AI assistant: OpenAI's Chat Completions API with the admin's own API key. */
class OpenAiPlugin extends ChatCompletionsPlugin
{
    /** OpenAI's reasoning models count their thinking in the answer's tokens, so they get more room. */
    protected const MAX_TOKENS = 16000;

    public function id(): string
    {
        return 'openai';
    }

    public function name(): string
    {
        return 'ChatGPT';
    }

    public function description(): string
    {
        return __('ChatGPT, OpenAI\'s models, for the AI assistant: it improves and translates texts you write, with your own API key.');
    }

    public function baseUrl(): string
    {
        return (string) config('gaiacp.openai.base_url') ?: 'https://api.openai.com/v1';
    }

    public function suggestedModels(): array
    {
        return ['gpt-5.5', 'gpt-5.6', 'gpt-5.4-mini'];
    }

    protected function tokenLimitField(): string
    {
        return 'max_completion_tokens';
    }

    /** Only GPT and o-series chat models, not images, audio, embeddings or moderation. */
    protected function writesText(string $model): bool
    {
        return preg_match('/^(gpt-|chatgpt-|o\d)/', $model) === 1
            && preg_match('/(image|audio|realtime|tts|transcribe|search|embedding|moderation|instruct)/', $model) !== 1;
    }

    public function settingsPage(): ?string
    {
        return OpenAiSettings::class;
    }

    public function pages(): array
    {
        return [OpenAiSettings::class];
    }
}
