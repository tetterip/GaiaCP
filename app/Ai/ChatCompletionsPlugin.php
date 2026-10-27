<?php

namespace App\Ai;

use App\Plugins\Plugin;
use App\Support\ModuleLogger;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * An AI service with an OpenAI-style Chat Completions API (`POST {base}/chat/completions`, bearer key,
 * `GET {base}/models`), such as DeepSeek, Kimi, OpenAI and Ollama. A plugin names its base URL and suggested models; settings are
 * `<id>.api_key` (encrypted) and `<id>.model`, edited on a ChatCompletionsSettings page. Without a key no Authorization
 * header is sent (a local Ollama needs none).
 */
abstract class ChatCompletionsPlugin extends Plugin implements AiModule
{
    /** The longest answer asked for, in tokens: some of these services refuse a larger max_tokens. */
    protected const MAX_TOKENS = 8192;

    /** e.g. https://api.deepseek.com */
    abstract public function baseUrl(): string;

    /** @return list<string> model ids offered under the model field (the service may have others) */
    abstract public function suggestedModels(): array;

    /** Off in a new install: it sends texts to the service, so the admin turns it on knowingly. */
    public function enabledByDefault(): bool
    {
        return false;
    }

    public function category(): string
    {
        return 'AI';
    }

    public function settingKeys(): array
    {
        return [$this->id().'.api_key', $this->id().'.model'];
    }

    public function encryptedSettingKeys(): array
    {
        return [$this->id().'.api_key'];
    }

    public function moduleLogs(): array
    {
        return [$this->id() => $this->name()];
    }

    public function aiKey(): string
    {
        return $this->id();
    }

    public function aiLabel(): string
    {
        return $this->name();
    }

    public function aiConfigured(): bool
    {
        return filled(config("gaiacp.{$this->id()}.api_key"));
    }

    /** The body field that limits the answer: OpenAI's newer models take only max_completion_tokens. */
    protected function tokenLimitField(): string
    {
        return 'max_tokens';
    }

    /** How long an answer may take, in seconds: a model on the admin's own machine can be slow. */
    protected function timeout(): int
    {
        return 120;
    }

    /** Whether a model the key lists can write texts (OpenAI also lists image, audio and embedding models). */
    protected function writesText(string $model): bool
    {
        return true;
    }

    public function model(): string
    {
        return trim((string) config("gaiacp.{$this->id()}.model")) ?: $this->suggestedModels()[0];
    }

    public function complete(Prompt $prompt): Answer
    {
        $response = $this->send('complete', fn (PendingRequest $request) => $request->post(rtrim($this->baseUrl(), '/').'/chat/completions', [
            'model' => $this->model(),
            'messages' => [
                ['role' => 'system', 'content' => $prompt->instructions],
                ['role' => 'user', 'content' => $prompt->text],
            ],
            $this->tokenLimitField() => min($prompt->maxTokens, static::MAX_TOKENS),
            'stream' => false,
        ]));

        $json = (array) $response->json();
        $choice = (array) data_get($json, 'choices.0', []);

        if (($choice['finish_reason'] ?? null) === 'content_filter') {
            throw new AiException(__(':service declined to answer this text.', ['service' => $this->name()]));
        }

        if (($choice['finish_reason'] ?? null) === 'length') {
            throw new AiException(__(':service\'s answer was too long and was cut off. Try a shorter text.', ['service' => $this->name()]));
        }

        return new Answer(
            text: (string) data_get($choice, 'message.content', ''),
            model: is_string($json['model'] ?? null) ? $json['model'] : $this->model(),
            inputTokens: (int) data_get($json, 'usage.prompt_tokens', 0),
            outputTokens: (int) data_get($json, 'usage.completion_tokens', 0),
        );
    }

    /**
     * The models the key may use (GET /models, which uses no tokens), kept an hour for the model field.
     *
     * @return list<string>
     *
     * @throws AiException
     */
    public function models(): array
    {
        if (! $this->aiConfigured()) {
            throw new AiException(__('Fill in the API key first.'));
        }

        $response = $this->send('models', fn (PendingRequest $request) => $request->get(rtrim($this->baseUrl(), '/').'/models'));
        $models = collect((array) $response->json('data'))->pluck('id')->filter(fn ($id) => is_string($id) && $id !== '' && $this->writesText($id))->sort()->values()->all();

        Cache::put($this->modelsCacheKey(), $models, now()->addHour());

        return $models;
    }

    /** @return list<string> the models last read, else the suggested ones */
    public function knownModels(): array
    {
        $read = Cache::get($this->modelsCacheKey());

        return is_array($read) && $read !== [] ? $read : $this->suggestedModels();
    }

    private function modelsCacheKey(): string
    {
        return "gaiacp.ai_models.{$this->id()}";
    }

    /**
     * @param  callable(PendingRequest): Response  $call
     *
     * @throws AiException with the service's message when the call fails
     */
    private function send(string $action, callable $call): Response
    {
        if (! $this->aiConfigured()) {
            throw new AiException(__('Fill in the :service API key under Settings → :service.', ['service' => $this->name()]));
        }

        $key = trim((string) config("gaiacp.{$this->id()}.api_key"));

        try {
            $response = $call(Http::timeout($this->timeout())
                ->connectTimeout(10)
                ->acceptJson()
                ->when($key !== '', fn (PendingRequest $request) => $request->withToken($key))
                ->withMiddleware(ModuleLogger::middleware($this->id(), $action, array_filter([$key]))));
        } catch (ConnectionException $e) {
            throw new AiException(__('Could not reach :service: :error', ['service' => $this->name(), 'error' => $e->getMessage()]), previous: $e);
        }

        if ($response->successful()) {
            return $response;
        }

        $message = (string) ($response->json('error.message') ?: (is_string($response->json('error')) ? $response->json('error') : "HTTP {$response->status()}"));

        throw new AiException(match (true) {
            $response->status() === 401 => __(':service did not accept the API key. Check it under Settings → :service.', ['service' => $this->name()]),
            $response->status() === 402, $response->json('error.code') === 'insufficient_quota' => __(':service says the account has no balance left. Top it up on their site.', ['service' => $this->name()]),
            in_array($response->status(), [429, 503, 529], true) => __(':service is busy right now. Try again in a minute.', ['service' => $this->name()]),
            default => __(':service answered: :error', ['service' => $this->name(), 'error' => $message]),
        });
    }
}
