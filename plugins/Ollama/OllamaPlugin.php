<?php

namespace Plugins\Ollama;

use App\Ai\ChatCompletionsPlugin;

/**
 * Ollama for the AI assistant: an open model (Llama, Qwen, Gemma…) running on the admin's own server or computer, through
 * Ollama's OpenAI-compatible API, so texts never leave their machines. No API key unless a proxy in front asks for one.
 */
class OllamaPlugin extends ChatCompletionsPlugin
{
    public function id(): string
    {
        return 'ollama';
    }

    public function name(): string
    {
        return 'Ollama';
    }

    public function description(): string
    {
        return __('Ollama for the AI assistant: an open model such as Llama, Qwen or Gemma on your own server or computer, so texts never leave your machines.');
    }

    public function baseUrl(): string
    {
        return trim((string) config('gaiacp.ollama.base_url')) ?: 'http://localhost:11434/v1';
    }

    public function suggestedModels(): array
    {
        return ['llama3.3', 'qwen3', 'gemma3', 'mistral'];
    }

    public function settingKeys(): array
    {
        return [...parent::settingKeys(), 'ollama.base_url'];
    }

    /** Set up once it has an address: Ollama itself asks for no key. */
    public function aiConfigured(): bool
    {
        return filled(config('gaiacp.ollama.base_url'));
    }

    /** A model on a CPU can take minutes for a long text. */
    protected function timeout(): int
    {
        return 600;
    }

    public function settingsPage(): ?string
    {
        return OllamaSettings::class;
    }

    public function pages(): array
    {
        return [OllamaSettings::class];
    }
}
