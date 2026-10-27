<?php

namespace Plugins\Claude;

use App\Ai\AiException;
use App\Ai\AiModule;
use App\Ai\Answer;
use App\Ai\Prompt;
use App\Plugins\Plugin;
use App\Support\ModuleLogger;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/** Claude, Anthropic's AI, for the AI assistant: one call to the Messages API per answer, with the admin's own API key. */
class ClaudePlugin extends Plugin implements AiModule
{
    public const API_URL = 'https://api.anthropic.com/v1';

    public const API_VERSION = '2026-10-21';

    /** model id => label (Settings → Claude) */
    public const MODELS = [
        'claude-opus-5-5' => 'Claude Opus 5.5',
        'claude-sonnet-5-5' => 'Claude Sonnet 5.5',
        'claude-haiku-5-5' => 'Claude Haiku 5.5',
    ];

    /** Models that may re-run a refused request on another model server-side (`fallbacks: "default"`); not Haiku. */
    private const FALLBACK_MODELS = ['claude-opus-5-5', 'claude-sonnet-5-5'];

    public function id(): string
    {
        return 'claude';
    }

    public function name(): string
    {
        return 'Claude';
    }

    public function description(): string
    {
        return __('Claude, Anthropic\'s AI, for the AI assistant: it improves and translates texts you write, with your own API key.');
    }

    public function category(): string
    {
        return 'AI';
    }

    /** Off in a new install: it sends texts to Anthropic, so the admin turns it on knowingly. */
    public function enabledByDefault(): bool
    {
        return false;
    }

    public function settingsPage(): ?string
    {
        return ClaudeSettings::class;
    }

    public function pages(): array
    {
        return [ClaudeSettings::class];
    }

    public function settingKeys(): array
    {
        return ['claude.api_key', 'claude.model'];
    }

    public function encryptedSettingKeys(): array
    {
        return ['claude.api_key'];
    }

    public function moduleLogs(): array
    {
        return ['claude' => 'Claude'];
    }

    public function aiKey(): string
    {
        return 'claude';
    }

    public function aiLabel(): string
    {
        return 'Claude';
    }

    public function aiConfigured(): bool
    {
        return filled(config('gaiacp.claude.api_key'));
    }

    public static function model(): string
    {
        $model = (string) config('gaiacp.claude.model');

        return array_key_exists($model, self::MODELS) ? $model : array_key_first(self::MODELS);
    }

    public function complete(Prompt $prompt): Answer
    {
        $model = self::model();
        $body = [
            'model' => $model,
            'max_tokens' => $prompt->maxTokens,
            'system' => $prompt->instructions,
            'messages' => [['role' => 'user', 'content' => $prompt->text]],
            // Rewriting and translating need little reasoning: low effort keeps answers quick and cheap
            'output_config' => ['effort' => 'low'],
        ];

        $request = $this->client($prompt->feature);

        if (in_array($model, self::FALLBACK_MODELS, true)) {
            $body['fallbacks'] = 'default';
            $request->withHeaders(['anthropic-beta' => 'server-side-fallback-2026-07-01']);
        }

        $response = $this->send(fn () => $request->post(self::API_URL.'/messages', $body));
        $json = (array) $response->json();

        if (($json['stop_reason'] ?? null) === 'refusal') {
            throw new AiException(__('Claude declined to answer this text.'));
        }

        if (($json['stop_reason'] ?? null) === 'max_tokens') {
            throw new AiException(__('Claude\'s answer was too long and was cut off. Try a shorter text.'));
        }

        $text = collect($json['content'] ?? [])->where('type', 'text')->pluck('text')->implode('');

        return new Answer(
            text: $text,
            model: is_string($json['model'] ?? null) ? $json['model'] : $model,
            inputTokens: (int) data_get($json, 'usage.input_tokens', 0) + (int) data_get($json, 'usage.cache_read_input_tokens', 0) + (int) data_get($json, 'usage.cache_creation_input_tokens', 0),
            outputTokens: (int) data_get($json, 'usage.output_tokens', 0),
        );
    }

    /**
     * Checks the key and the model (GET /v1/models/{model}, which uses no tokens).
     *
     * @return string the model's name as Anthropic gives it
     *
     * @throws AiException
     */
    public function testConnection(): string
    {
        if (! $this->aiConfigured()) {
            throw new AiException(__('Fill in the API key first.'));
        }

        $response = $this->send(fn () => $this->client('test')->get(self::API_URL.'/models/'.self::model()));

        return (string) ($response->json('display_name') ?: self::model());
    }

    private function client(string $action): PendingRequest
    {
        $key = trim((string) config('gaiacp.claude.api_key'));

        return Http::timeout(120)
            ->connectTimeout(10)
            ->acceptJson()
            ->withHeaders(['x-api-key' => $key, 'anthropic-version' => self::API_VERSION])
            ->withMiddleware(ModuleLogger::middleware('claude', $action, [$key]));
    }

    /**
     * @param  callable(): Response  $call
     *
     * @throws AiException with Anthropic's message when the call fails
     */
    private function send(callable $call): Response
    {
        if (! $this->aiConfigured()) {
            throw new AiException(__('Fill in the Claude API key under Settings → Claude.'));
        }

        try {
            $response = $call();
        } catch (ConnectionException $e) {
            throw new AiException(__('Could not reach Claude: :error', ['error' => $e->getMessage()]), previous: $e);
        }

        if ($response->successful()) {
            return $response;
        }

        $message = (string) ($response->json('error.message') ?: "HTTP {$response->status()}");

        throw new AiException(match (true) {
            $response->status() === 401 => __('Claude did not accept the API key. Check it under Settings → Claude.'),
            $response->status() === 429, $response->status() === 529 => __('Claude is busy right now. Try again in a minute.'),
            default => __('Claude answered: :error', ['error' => $message]),
        });
    }
}
