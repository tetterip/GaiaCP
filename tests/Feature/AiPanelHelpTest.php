<?php

namespace Tests\Feature;

use App\Filament\Pages\Help;
use App\Models\Client;
use App\Models\User;
use App\Plugins\PluginManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/** System → Help → Ask the AI assistant: a "how do I…" question answered from the closest sections of the admin guide. */
class AiPanelHelpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $plugins = app(PluginManager::class);
        $plugins->enable($plugins->find('claude'));
        config(['gaiacp.claude.api_key' => 'sk-ant-secret']);
        $this->actingAs(User::factory()->create());
    }

    public function test_the_closest_sections_of_the_guide_are_picked(): void
    {
        $sections = Help::sectionsFor('How do I set the price of a new TLD?', 'en');

        $this->assertNotEmpty($sections);
        $this->assertStringStartsWith('05-domains-and-ssl.md#domain-pricing', array_key_first($sections));
        $this->assertLessThanOrEqual(Help::AI_CONTEXT, mb_strlen(implode('', $sections)));
    }

    public function test_a_question_is_answered_from_the_guide_with_links_and_nothing_about_clients(): void
    {
        Client::factory()->create(['first_name' => 'Xanthippi', 'email' => 'xanthippi@example.gr']);
        Http::fake(['api.anthropic.com/v1/messages' => Http::response([
            'type' => 'message', 'role' => 'assistant', 'model' => 'claude-opus-5-5', 'stop_reason' => 'end_turn',
            'content' => [['type' => 'text', 'text' => "Open **Settings → Domain pricing** and add the TLD.\n\n[Domain pricing](05-domains-and-ssl.md#domain-pricing)"]],
            'usage' => ['input_tokens' => 3000, 'output_tokens' => 60],
        ])]);

        Livewire::test(Help::class)
            ->set('q', 'how do I add a TLD')
            ->assertSee(__('Ask the AI assistant'))
            ->call('ask')
            ->assertSeeHtml('<strong>Settings → Domain pricing</strong>')
            ->assertSeeHtml('href="'.e(Help::chapterUrl('domains-and-ssl', 'domain-pricing')).'"')
            ->set('q', 'something else')
            ->assertSet('answer', null);

        Http::assertSent(function (Request $request) {
            $sent = json_encode($request->data(), JSON_UNESCAPED_UNICODE);

            return str_contains($sent, 'how do I add a TLD') && str_contains($sent, '05-domains-and-ssl.md#domain-pricing')
                && ! str_contains($sent, 'Xanthippi') && ! str_contains($sent, 'xanthippi@example.gr');
        });
    }

    public function test_no_button_while_the_switch_is_off_or_nothing_is_typed(): void
    {
        Livewire::test(Help::class)->set('q', 'tl')->assertDontSee(__('Ask the AI assistant'));

        config(['gaiacp.ai.features.help' => false]);
        Livewire::test(Help::class)->set('q', 'how do I add a TLD')->assertDontSee(__('Ask the AI assistant'))->call('ask')->assertSet('answer', null);
        Http::assertNothingSent();
    }
}
