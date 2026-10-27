<?php

namespace Tests\Feature;

use App\Enums\AdminArea;
use App\Filament\Clusters\Settings\Pages\LanguageSettings;
use App\Models\User;
use App\Plugins\PluginManager;
use App\Support\Translations;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/** Settings → Languages → Translate with AI: a language's missing texts, a batch per request, saved like an upload. */
class AiLanguageFilesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['gaiacp.locales' => null]);
        File::delete(Translations::overridePath('da.json'));
        Translations::flush();

        $plugins = app(PluginManager::class);
        $plugins->enable($plugins->find('claude'));
        config(['gaiacp.claude.api_key' => 'sk-ant-secret']);
        $this->actingAs(User::factory()->create());
    }

    protected function tearDown(): void
    {
        File::delete(Translations::overridePath('da.json'));
        Translations::flush();
        parent::tearDown();
    }

    /** Claude "translates" each text it is sent: [da] before it, but $same as it is and $lost without its placeholders. */
    private function claudeTranslates(string $same, string $lost): void
    {
        Http::fake(['api.anthropic.com/v1/messages' => function (Request $request) use ($same, $lost) {
            preg_match('~<text>\s*(.*?)\s*</text>~s', $request['messages'][0]['content'], $m);
            $texts = json_decode($m[1], true);
            $answer = [];
            foreach ($texts as $text) {
                $answer[$text] = match ($text) {
                    $same => $text,
                    $lost => '[da] no placeholders',
                    default => "[da] {$text}",
                };
            }

            return Http::response([
                'type' => 'message', 'role' => 'assistant', 'model' => 'claude-opus-5-5', 'stop_reason' => 'end_turn',
                'content' => [['type' => 'text', 'text' => json_encode($answer, JSON_UNESCAPED_UNICODE)]],
                'usage' => ['input_tokens' => 1500, 'output_tokens' => 1500],
            ]);
        }]);
    }

    public function test_a_batch_of_missing_texts_is_translated_and_saved_until_stopped(): void
    {
        $missing = count(Translations::export('da'));
        $first = array_slice(array_keys(Translations::export('da')), 0, LanguageSettings::AI_BATCH);
        $same = collect($first)->first(fn (string $text) => ! str_contains($text, ':') && trim($text) === $text);
        $lost = collect($first)->first(fn (string $text) => preg_match('/(?<![\w:\/]):[a-z_]+/', $text) === 1);
        $this->claudeTranslates($same, $lost);
        $done = collect($first)->first(fn (string $text) => ! in_array($text, [$same, $lost], true) && trim($text) === $text);

        $page = Livewire::test(LanguageSettings::class)
            ->callAction('aiTranslate', ['locale' => 'da'])
            ->assertHasNoErrors()
            ->assertSet('aiLocale', 'da');

        $saved = json_decode(File::get(Translations::overridePath('da.json')), true);
        $this->assertSame("[da] {$done}", $saved[$done]);
        $this->assertArrayNotHasKey($same, $saved, 'the same as the English is skipped');
        $this->assertArrayNotHasKey($lost, $saved, 'a lost placeholder is skipped');
        $this->assertSame($page->get('aiSaved'), count($saved));
        $this->assertLessThanOrEqual(LanguageSettings::AI_BATCH, count($saved) + 2);
        $this->assertCount($missing - count($saved), Translations::export('da'));
        $this->assertContains($same, $page->get('aiSkipped'));
        $this->assertContains($lost, $page->get('aiSkipped'));

        // The next batch asks only for texts not asked yet
        $page->call('translateBatch');
        $asked = Http::recorded()->map(fn (array $pair) => $pair[0]['messages'][0]['content'])->last();
        $this->assertStringNotContainsString(json_encode($same, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $asked);

        $page->callAction(TestAction::make('aiStop')->schemaComponent('aiProgress', 'form'))->assertSet('aiLocale', null);
        $count = Http::recorded()->count();
        $page->call('translateBatch');
        $this->assertCount($count, Http::recorded(), 'nothing more once stopped');
    }

    public function test_only_super_admins_with_the_switch_see_it(): void
    {
        config(['gaiacp.ai.features.language_files' => false]);
        Livewire::test(LanguageSettings::class)->assertActionHidden('aiTranslate');

        config(['gaiacp.ai.features.language_files' => true]);
        $this->actingAs(User::factory()->limitedTo([AdminArea::BusinessSettings])->create());
        Livewire::test(LanguageSettings::class)->assertActionHidden('aiTranslate');
        Http::assertNothingSent();
    }
}
