<?php

namespace Tests\Feature;

use App\Actions\Tickets;
use App\Ai\AiException;
use App\Ai\Assistant;
use App\Enums\UserRole;
use App\Filament\Clusters\Settings\Pages\AiSettings;
use App\Filament\Resources\KbArticles\Pages\CreateKbArticle;
use App\Filament\Resources\ProductGroups\Pages\ManageProductGroups;
use App\Filament\Resources\Tickets\Pages\ViewTicket;
use App\Models\AiUsage;
use App\Models\Client;
use App\Models\ProductGroup;
use App\Models\User;
use App\Plugins\PluginManager;
use Filament\Actions\Testing\TestAction;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Plugins\Claude\ClaudePlugin;
use Plugins\Claude\ClaudeSettings;
use Tests\TestCase;

/** The AI assistant (Settings → AI assistant) with Claude: Improve and Translate on text fields, suggestions only. */
class AiAssistantTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $plugins = app(PluginManager::class);
        $plugins->enable($plugins->find('claude'));
        config(['gaiacp.claude.api_key' => 'sk-ant-secret', 'gaiacp.claude.model' => 'claude-opus-5-5']);

        $this->admin = User::factory()->create();
        $this->actingAs($this->admin);
    }

    /** The Messages API answering $text. */
    private function fakeClaude(string $text = 'Καλησπέρα σας, δοκιμάστε τη θύρα 587.', array $answer = [], int $status = 200): void
    {
        Http::fake(['api.anthropic.com/v1/messages' => Http::response([
            'id' => 'msg_1',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-opus-5-5',
            'content' => [['type' => 'text', 'text' => $text]],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 320, 'output_tokens' => 40],
            ...$answer,
        ], $status)]);
    }

    public function test_improve_sends_only_the_text_with_fixed_instructions_and_keeps_the_usage(): void
    {
        $this->fakeClaude('Please try port 587.');

        $this->assertSame('Please try port 587.', app(Assistant::class)->improve('pls try port 587', 'fix'));

        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.anthropic.com/v1/messages'
            && $request->header('x-api-key') === ['sk-ant-secret']
            && $request->header('anthropic-version') === [ClaudePlugin::API_VERSION]
            && $request->header('anthropic-beta') === ['server-side-fallback-2026-07-01']
            && $request['model'] === 'claude-opus-5-5'
            && $request['fallbacks'] === 'default'
            && $request['output_config'] === ['effort' => 'low']
            && $request['messages'] === [['role' => 'user', 'content' => "<text>\npls try port 587\n</text>"]]
            && str_contains($request['system'], 'Fix the spelling'));

        $usage = AiUsage::query()->sole();
        $this->assertSame([$this->admin->id, 'improve', 'claude', 'claude-opus-5-5', 320, 40], [$usage->user_id, $usage->feature, $usage->provider, $usage->model, $usage->input_tokens, $usage->output_tokens]);
        $this->assertSame(360, AiUsage::tokensThisMonth());
    }

    public function test_haiku_is_asked_without_a_fallback(): void
    {
        config(['gaiacp.claude.model' => 'claude-haiku-5-5']);
        $this->fakeClaude('Hello');

        app(Assistant::class)->translate('Γεια', 'en');

        Http::assertSent(fn (Request $request) => $request['model'] === 'claude-haiku-5-5'
            && ! isset($request['fallbacks']) && $request->header('anthropic-beta') === []
            && str_contains($request['system'], 'into English (en)'));
    }

    public function test_nothing_is_sent_while_the_plugin_the_key_or_the_feature_is_off(): void
    {
        Http::fake();

        config(['gaiacp.ai.features.translate' => false]);
        $this->assertTrue(Assistant::enabled('improve'));
        $this->assertFalse(Assistant::enabled('translate'));

        config(['gaiacp.claude.api_key' => null]);
        $this->assertFalse(Assistant::enabled('improve'));

        config(['gaiacp.claude.api_key' => 'sk-ant-secret']);
        $plugins = app(PluginManager::class);
        $plugins->disable($plugins->find('claude'));
        $this->assertNull(Assistant::provider());

        try {
            app(Assistant::class)->improve('Hello', 'fix');
            $this->fail('the assistant answered while off');
        } catch (AiException $e) {
            $this->assertStringContainsString('off', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_the_monthly_limit_stops_it(): void
    {
        Http::fake();
        config(['gaiacp.ai.monthly_tokens' => 1000]);
        AiUsage::query()->create(['feature' => 'improve', 'provider' => 'claude', 'input_tokens' => 900, 'output_tokens' => 100, 'created_at' => now()]);
        AiUsage::query()->create(['feature' => 'improve', 'provider' => 'claude', 'input_tokens' => 5000, 'created_at' => now()->subMonth()]);

        $this->expectExceptionMessage('limit of 1,000 tokens is used up');

        try {
            app(Assistant::class)->improve('Hello', 'fix');
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_claude_errors_and_refusals_are_explained(): void
    {
        Http::fakeSequence('api.anthropic.com/*')
            ->push(['type' => 'error', 'error' => ['type' => 'authentication_error', 'message' => 'invalid x-api-key']], 401)
            ->push(['content' => [], 'stop_reason' => 'refusal', 'usage' => ['input_tokens' => 10, 'output_tokens' => 0]])
            ->push(['type' => 'error', 'error' => ['type' => 'invalid_request_error', 'message' => 'max_tokens: too large']], 400);

        $this->assertAiFails('did not accept the API key');
        $this->assertAiFails('declined');
        $this->assertAiFails('Claude answered: max_tokens: too large');

        $this->assertSame(0, AiUsage::query()->count(), 'no usage kept without an answer');
    }

    private function assertAiFails(string $message): void
    {
        try {
            app(Assistant::class)->improve('Hello', 'fix');
            $this->fail('no error');
        } catch (AiException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }
    }

    public function test_an_empty_or_too_long_text_is_not_sent(): void
    {
        Http::fake();

        foreach (['  ', str_repeat('a', Assistant::MAX_TEXT + 1)] as $text) {
            try {
                app(Assistant::class)->translate($text, 'el');
                $this->fail('sent');
            } catch (AiException) {
            }
        }

        Http::assertNothingSent();
    }

    public function test_a_ticket_reply_is_improved_and_translated_into_the_clients_language(): void
    {
        $client = Client::factory()->create(['locale' => 'el']);
        $user = User::factory()->create(['role' => UserRole::Client, 'client_id' => $client->id, 'email' => $client->email]);
        $ticket = app(Tickets::class)->open($client, $user, 'Email', 'Δεν στέλνω email.');
        $this->fakeClaude('Δοκιμάστε τη θύρα 587.');

        Livewire::test(ViewTicket::class, ['record' => $ticket->getRouteKey()])
            ->mountAction('reply')
            ->fillForm(['body' => 'Try port 587.'])
            ->mountAction(TestAction::make('aiTranslate_client')->schemaComponent('body', schema: 'mountedActionSchema0'))
            ->assertSchemaStateSet(['result' => 'Δοκιμάστε τη θύρα 587.'], 'mountedActionSchema1')
            ->callMountedAction()
            ->assertSchemaStateSet(['body' => 'Δοκιμάστε τη θύρα 587.'], 'mountedActionSchema0');

        Http::assertSent(fn (Request $request) => str_contains($request['system'], 'into Greek (el)') && str_contains($request['messages'][0]['content'], 'Try port 587.'));
        $this->assertCount(1, $ticket->messages()->get(), 'nothing is sent to the client by the assistant');
    }

    public function test_a_failed_answer_leaves_the_text_as_it_was(): void
    {
        $client = Client::factory()->create();
        $user = User::factory()->create(['role' => UserRole::Client, 'client_id' => $client->id, 'email' => $client->email]);
        $ticket = app(Tickets::class)->open($client, $user, 'Email', 'Help');
        $this->fakeClaude(answer: ['type' => 'error', 'error' => ['type' => 'overloaded_error', 'message' => 'Overloaded']], status: 529);

        Livewire::test(ViewTicket::class, ['record' => $ticket->getRouteKey()])
            ->mountAction('reply')
            ->fillForm(['body' => 'pls wait'])
            ->mountAction(TestAction::make('aiImprove_polite')->schemaComponent('body', schema: 'mountedActionSchema0'))
            ->assertNotified('The AI assistant gave no answer')
            ->assertSchemaStateSet(['body' => 'pls wait'], 'mountedActionSchema0');
    }

    public function test_a_kb_article_is_translated_from_the_other_language(): void
    {
        config(['gaiacp.locales' => ['en' => 'English', 'el' => 'Ελληνικά']]);
        $this->fakeClaude('Πώς ρυθμίζω το email μου');

        Livewire::test(CreateKbArticle::class)
            ->assertSee(__('AI assistant'))
            ->fillForm(['texts' => ['en' => ['title' => 'Email', 'body' => 'How I set up my email']]])
            ->callAction(TestAction::make('aiFrom_en')->schemaComponent('texts.el.body', 'form'))
            ->assertSchemaStateSet(fn (array $state) => ['texts' => ['el' => ['body' => 'Πώς ρυθμίζω το email μου', 'title' => null], 'en' => $state['texts']['en']]]);

        Http::assertSent(fn (Request $request) => str_contains($request['system'], 'into Greek (el)') && str_contains($request['messages'][0]['content'], 'How I set up my email'));
    }

    public function test_greek_typed_in_a_product_groups_english_tab_is_translated_into_english(): void
    {
        config(['gaiacp.locales' => ['en' => 'English', 'el' => 'Ελληνικά']]);
        $group = ProductGroup::create(['names' => ['en' => 'Hosting'], 'descriptions' => ['en' => 'Πακέτα φιλοξενίας σε γρήγορους server']]);
        $this->fakeClaude('Hosting plans on fast servers');
        $field = fn (string $action) => TestAction::make($action)->schemaComponent('texts-en.descriptions.en', schema: 'mountedActionSchema0');

        Livewire::test(ManageProductGroups::class)
            ->mountAction(TestAction::make('edit')->table($group))
            // The Greek tab is empty: say so instead of "Write some text first."
            ->mountAction($field('aiFrom_el'))
            ->assertNotified(Notification::make()->danger()->title(__('The AI assistant gave no answer'))
                ->body(__('The :language text is empty, so there is nothing to translate from.', ['language' => 'Ελληνικά'])))
            ->mountAction($field('aiTranslate_en'))
            ->assertSchemaStateSet(['result' => 'Hosting plans on fast servers'], 'mountedActionSchema1')
            ->callMountedAction()
            ->assertSchemaStateSet(fn (array $state) => ['descriptions' => ['en' => 'Hosting plans on fast servers', 'el' => null]], 'mountedActionSchema0');

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => str_contains($request['system'], 'into English (en)') && str_contains($request['messages'][0]['content'], 'Πακέτα φιλοξενίας σε γρήγορους server'));
    }

    public function test_the_buttons_are_hidden_while_the_assistant_is_off(): void
    {
        config(['gaiacp.ai.features.improve' => false, 'gaiacp.ai.features.translate' => false]);

        Livewire::test(CreateKbArticle::class)
            ->assertActionDoesNotExist(TestAction::make('aiImprove_fix')->schemaComponent('texts.en.body', 'form'))
            ->assertDontSee(__('AI assistant'));
    }

    public function test_test_connection_reads_the_model_without_using_tokens(): void
    {
        Http::fake(['api.anthropic.com/v1/models/claude-sonnet-5-5' => Http::response(['type' => 'model', 'id' => 'claude-sonnet-5-5', 'display_name' => 'Claude Sonnet 5.5'])]);

        Livewire::test(ClaudeSettings::class)
            ->fillForm(['claude' => ['api_key' => 'sk-ant-typed', 'model' => 'claude-sonnet-5-5']])
            ->callAction('testConnection')
            ->assertNotified('Connected: Claude Sonnet 5.5');

        Http::assertSent(fn (Request $request) => $request->method() === 'GET' && $request->header('x-api-key') === ['sk-ant-typed']);
        $this->assertSame(0, AiUsage::query()->count());
    }

    public function test_settings_are_saved_and_the_key_never_reaches_the_browser(): void
    {
        Livewire::test(AiSettings::class)
            ->fillForm(['ai' => ['features' => ['improve' => true, 'translate' => false], 'monthly_tokens' => '50000']])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertFalse(config('gaiacp.ai.features.translate'));
        $this->assertSame(50000, config('gaiacp.ai.monthly_tokens'));

        Livewire::test(ClaudeSettings::class)
            ->assertSchemaStateSet(['claude' => ['api_key' => null, 'model' => 'claude-opus-5-5']])
            ->fillForm(['claude' => ['api_key' => 'sk-ant-new', 'model' => 'claude-haiku-5-5']])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('sk-ant-new', config('gaiacp.claude.api_key'));
        $this->assertSame('claude-haiku-5-5', ClaudePlugin::model());
    }

    public function test_new_installs_start_with_claude_off(): void
    {
        $this->assertFalse((new ClaudePlugin)->enabledByDefault());
    }
}
