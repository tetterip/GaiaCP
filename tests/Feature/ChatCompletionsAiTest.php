<?php

namespace Tests\Feature;

use App\Ai\AiException;
use App\Ai\Assistant;
use App\Filament\Clusters\Settings\Pages\AiSettings;
use App\Filament\Pages\Plugins;
use App\Models\AiUsage;
use App\Models\User;
use App\Plugins\PluginManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Plugins\Claude\ClaudeSettings;
use Plugins\DeepSeek\DeepSeekPlugin;
use Plugins\DeepSeek\DeepSeekSettings;
use Plugins\Kimi\KimiPlugin;
use Plugins\Kimi\KimiSettings;
use Plugins\OpenAi\OpenAiPlugin;
use Plugins\OpenAi\OpenAiSettings;
use Tests\TestCase;

/** DeepSeek, Kimi and ChatGPT (OpenAI) as the AI assistant's service: OpenAI-style Chat Completions with the admin's key. */
class ChatCompletionsAiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $plugins = app(PluginManager::class);
        $plugins->enable($plugins->find('deepseek'));
        $plugins->enable($plugins->find('kimi'));
        $plugins->enable($plugins->find('openai'));
        config(['gaiacp.deepseek.api_key' => 'sk-deep', 'gaiacp.kimi.api_key' => 'sk-kimi', 'gaiacp.openai.api_key' => 'sk-proj-openai']);

        $this->actingAs(User::factory()->create());
    }

    private function answer(string $text, string $finish = 'stop'): array
    {
        return [
            'id' => 'chatcmpl-1',
            'object' => 'chat.completion',
            'model' => 'deepseek-chat',
            'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => $text], 'finish_reason' => $finish]],
            'usage' => ['prompt_tokens' => 200, 'completion_tokens' => 30, 'total_tokens' => 230],
        ];
    }

    public function test_the_chosen_service_answers_with_the_bearer_key_and_its_tokens_are_kept(): void
    {
        config(['gaiacp.ai.provider' => 'deepseek']);
        Http::fake(['api.deepseek.com/chat/completions' => Http::response($this->answer('Please try port 587.'))]);

        $this->assertSame('deepseek', Assistant::provider()->aiKey());
        $this->assertSame('Please try port 587.', app(Assistant::class)->improve('pls try port 587', 'fix'));

        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.deepseek.com/chat/completions'
            && $request->header('Authorization') === ['Bearer sk-deep']
            && $request['model'] === 'deepseek-chat'
            && $request['max_tokens'] === 8192
            && $request['messages'][0]['role'] === 'system' && str_contains($request['messages'][0]['content'], 'Fix the spelling')
            && $request['messages'][1] === ['role' => 'user', 'content' => "<text>\npls try port 587\n</text>"]);

        $usage = AiUsage::query()->sole();
        $this->assertSame(['deepseek', 'deepseek-chat', 200, 30], [$usage->provider, $usage->model, $usage->input_tokens, $usage->output_tokens]);
    }

    public function test_kimi_uses_its_own_address_and_the_model_typed_in(): void
    {
        config(['gaiacp.ai.provider' => 'kimi', 'gaiacp.kimi.model' => 'kimi-k3']);
        Http::fake(['api.moonshot.ai/v1/chat/completions' => Http::response($this->answer('Γεια σας'))]);

        $this->assertSame('Γεια σας', app(Assistant::class)->translate('Hello', 'el'));

        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.moonshot.ai/v1/chat/completions'
            && $request->header('Authorization') === ['Bearer sk-kimi'] && $request['model'] === 'kimi-k3');
    }

    public function test_chatgpt_limits_the_answer_with_max_completion_tokens_and_lists_only_chat_models(): void
    {
        config(['gaiacp.ai.provider' => 'openai']);
        Http::fake([
            'api.openai.com/v1/chat/completions' => Http::response($this->answer('Please try port 587.')),
            'api.openai.com/v1/models' => Http::response(['object' => 'list', 'data' => collect(['gpt-5.5', 'gpt-image-2', 'text-embedding-3-small', 'o4-mini', 'gpt-realtime', 'whisper-1', 'gpt-5.4-mini'])
                ->map(fn ($id) => ['id' => $id, 'object' => 'model'])->all()]),
        ]);

        $this->assertSame('Please try port 587.', app(Assistant::class)->improve('pls try port 587', 'fix'));

        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.openai.com/v1/chat/completions'
            && $request->header('Authorization') === ['Bearer sk-proj-openai']
            && $request['model'] === 'gpt-5.5'
            && $request['max_completion_tokens'] === 16000
            && ! isset($request['max_tokens']));

        $this->assertSame(['gpt-5.4-mini', 'gpt-5.5', 'o4-mini'], (new OpenAiPlugin)->models());
    }

    public function test_chatgpt_without_credit_says_so(): void
    {
        config(['gaiacp.ai.provider' => 'openai']);
        Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => 'You exceeded your current quota', 'type' => 'insufficient_quota', 'code' => 'insufficient_quota']], 429)]);

        $this->expectExceptionMessage('ChatGPT says the account has no balance left.');

        app(Assistant::class)->improve('Hello', 'fix');
    }

    public function test_chatgpt_settings_are_saved_and_it_is_off_by_default(): void
    {
        Livewire::test(OpenAiSettings::class)
            ->fillForm(['openai' => ['api_key' => 'sk-proj-new', 'model' => 'gpt-5.6']])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('sk-proj-new', config('gaiacp.openai.api_key'));
        $this->assertSame('gpt-5.6', (new OpenAiPlugin)->model());
        $this->assertFalse((new OpenAiPlugin)->enabledByDefault());
    }

    public function test_the_ai_services_have_their_own_settings_group_and_plugin_category(): void
    {
        foreach ([AiSettings::class, ClaudeSettings::class, OpenAiSettings::class, DeepSeekSettings::class, KimiSettings::class] as $page) {
            $this->assertSame(__('AI assistant'), $page::getNavigationGroup(), $page);
        }
        foreach (app(PluginManager::class)->aiModules() as $module) {
            $this->assertSame('AI', $module->category());
        }
        $this->assertSame(__('AI assistant'), Plugins::categoryLabel('AI'));
    }

    public function test_errors_are_explained_and_cost_nothing(): void
    {
        config(['gaiacp.ai.provider' => 'deepseek']);
        Http::fakeSequence('api.deepseek.com/*')
            ->push(['error' => ['message' => 'Authentication Fails', 'type' => 'authentication_error']], 401)
            ->push(['error' => ['message' => 'Insufficient Balance']], 402)
            ->push($this->answer('cut', 'length'))
            ->push(['error' => ['message' => 'Model Not Exist']], 400);

        foreach (['did not accept the API key', 'no balance left', 'too long', 'DeepSeek answered: Model Not Exist'] as $expected) {
            try {
                app(Assistant::class)->improve('Hello', 'fix');
                $this->fail('no error');
            } catch (AiException $e) {
                $this->assertStringContainsString($expected, $e->getMessage());
            }
        }

        $this->assertSame(0, AiUsage::query()->count());
    }

    public function test_test_connection_lists_the_models_and_warns_about_a_missing_one(): void
    {
        Http::fake(['api.moonshot.ai/v1/models' => Http::response(['object' => 'list', 'data' => [['id' => 'kimi-k3', 'object' => 'model'], ['id' => 'kimi-k2.6', 'object' => 'model']]])]);

        Livewire::test(KimiSettings::class)
            ->fillForm(['kimi' => ['api_key' => 'sk-typed', 'model' => 'kimi-old']])
            ->callAction('testConnection')
            ->assertNotified('Connected, but this key has no model kimi-old');

        Http::assertSent(fn (Request $request) => $request->method() === 'GET' && $request->header('Authorization') === ['Bearer sk-typed']);
        $this->assertSame(['kimi-k2.6', 'kimi-k3'], (new KimiPlugin)->knownModels(), 'the model field offers what was read');

        Livewire::test(KimiSettings::class)
            ->fillForm(['kimi' => ['model' => 'kimi-k3']])
            ->callAction('testConnection')
            ->assertNotified('Connected: kimi-k3');
    }

    public function test_settings_are_saved_and_new_installs_start_with_both_off(): void
    {
        Livewire::test(KimiSettings::class)
            ->assertSchemaStateSet(['kimi' => ['api_key' => null, 'model' => null]])
            ->fillForm(['kimi' => ['api_key' => 'sk-new', 'model' => 'kimi-k3']])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('sk-new', config('gaiacp.kimi.api_key'));
        $this->assertSame('kimi-k3', (new KimiPlugin)->model());
        $this->assertSame('deepseek-chat', (new DeepSeekPlugin)->model());
        $this->assertFalse((new KimiPlugin)->enabledByDefault());
        $this->assertFalse((new DeepSeekPlugin)->enabledByDefault());
    }
}
