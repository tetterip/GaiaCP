<?php

namespace Plugins\Ollama;

use App\Ai\ChatCompletionsSettings;
use Filament\Forms\Components\TextInput;

/** Settings → Ollama: the address of the Ollama server, an API key only for a proxy that asks for one, and the model. */
class OllamaSettings extends ChatCompletionsSettings
{
    protected static ?int $navigationSort = 85;

    protected static ?string $title = 'Ollama';

    protected static ?string $slug = 'ollama';

    public static function pluginId(): string
    {
        return 'ollama';
    }

    public static function keys(): array
    {
        return ['ollama.base_url', ...parent::keys()];
    }

    public static function settingsDescription(): string
    {
        return __('Ollama for the AI assistant: an open model such as Llama, Qwen or Gemma on your own server or computer, so texts never leave your machines.');
    }

    protected static function howTo(): string
    {
        return __('Install Ollama (<strong>ollama.com</strong>) on this server or on a machine it can reach, download a model (<strong>ollama pull llama3.3</strong>) and fill in its address. On another machine, start Ollama with OLLAMA_HOST=0.0.0.0 and let only this server reach port 11434.');
    }

    protected static function billing(): string
    {
        return __('Nothing is billed: the model runs on your machine, which needs enough memory for it (about 8 GB for a small model). Texts are sent only when an admin presses an AI button.');
    }

    protected static function connectionFields(string $id): array
    {
        return [
            TextInput::make('ollama.base_url')
                ->label('Address')
                ->url()
                ->required()
                ->placeholder('http://localhost:11434/v1')
                ->helperText(__('Ollama\'s OpenAI-compatible address, ending in /v1.'))
                ->maxLength(255),
        ];
    }
}
