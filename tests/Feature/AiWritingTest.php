<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Client\Pages\SearchDomains;
use App\Filament\Clusters\Settings\Pages\AiSettings;
use App\Filament\Clusters\Settings\Pages\ClientAreaSettings;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Models\AiUsage;
use App\Models\Client;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\Registrar;
use App\Models\Server;
use App\Models\Service;
use App\Models\Tld;
use App\Models\User;
use App\Monitoring\MonitorStatus;
use App\Plugins\PluginManager;
use App\Support\DomainSearch;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/** The AI assistant writing texts from GaiaCP's own details: product descriptions, the server problem notice, domain names. */
class AiWritingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $plugins = app(PluginManager::class);
        $plugins->enable($plugins->find('claude'));
        config(['gaiacp.claude.api_key' => 'sk-ant-secret', 'gaiacp.languages.default' => 'el']);
        app()->setLocale('el');
    }

    /** The Messages API answering $text. */
    private function claudeAnswers(string $text): void
    {
        Http::fake(['api.anthropic.com/v1/messages' => Http::response([
            'id' => 'msg_1',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-opus-5-5',
            'content' => [['type' => 'text', 'text' => $text]],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 120, 'output_tokens' => 40],
        ])]);
    }

    private function sentToClaude(): string
    {
        $request = Http::recorded(fn (Request $request) => str_contains($request->url(), 'anthropic'))->sole()[0];

        return $request['system']."\n".$request['messages'][0]['content'];
    }

    public function test_a_products_description_is_written_from_its_details_without_prices(): void
    {
        $this->actingAs(User::factory()->create());
        $group = ProductGroup::create(['names' => ['en' => 'Shared hosting', 'el' => 'Φιλοξενία'], 'features' => [['key' => 'disk', 'names' => ['en' => 'Disk space']]]]);
        $product = Product::factory()->priced(12000)->create(['name' => 'Pro', 'package' => 'gaia_pro', 'product_group_id' => $group->id, 'features' => ['disk' => '50 GB']]);
        $this->claudeAnswers('Για επαγγελματικά sites με 50 GB χώρο.');

        Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->callAction(TestAction::make('aiWrite')->schemaComponent('description', 'form'))
            ->assertSchemaStateSet(['description' => 'Για επαγγελματικά sites με 50 GB χώρο.']);

        $sent = $this->sentToClaude();
        $this->assertStringContainsString('in Greek (el)', $sent);
        $this->assertStringContainsString('Name: Pro', $sent);
        $this->assertStringContainsString('Type: Web hosting', $sent);
        $this->assertStringContainsString('Package on the server: gaia_pro', $sent);
        $this->assertStringContainsString('Group on the order page: Φιλοξενία', $sent);
        $this->assertStringContainsString('Compare table: Disk space: 50 GB', $sent);
        $this->assertStringNotContainsString('120', $sent, 'no price is sent');
        $this->assertSame('products', AiUsage::query()->sole()->feature);
    }

    public function test_the_server_problem_notice_is_written_from_the_monitors_without_hostnames(): void
    {
        $this->actingAs(User::factory()->create());
        $down = Server::factory()->create(['name' => 'nl1-internal', 'public_name' => 'Server Amsterdam 1', 'hostname' => 'nl1.secret-host.example']);
        $down->forceFill(['monitor_status' => MonitorStatus::Down, 'monitor_status_since' => now()->subMinutes(20)])->save();
        $plain = Server::factory()->create(['name' => 'hidden-name', 'public_name' => null]);
        $plain->forceFill(['monitor_status' => MonitorStatus::Maintenance, 'monitor_status_since' => now()->subHour()])->save();
        Server::factory()->create(['name' => 'fine'])->forceFill(['monitor_status' => MonitorStatus::Up])->save();
        Service::factory()->count(2)->create(['server_id' => $down->id]);
        $this->claudeAnswers('Γνωρίζουμε το πρόβλημα στον Server Amsterdam 1 και το διορθώνουμε.');

        Livewire::test(ClientAreaSettings::class)
            ->callAction(TestAction::make('aiWrite')->schemaComponent('client_area.incident_message', 'form'))
            ->assertSchemaStateSet(fn (array $state) => ['client_area' => ['incident_message' => 'Γνωρίζουμε το πρόβλημα στον Server Amsterdam 1 και το διορθώνουμε.'] + $state['client_area']]);

        $sent = $this->sentToClaude();
        $this->assertStringContainsString('Server Amsterdam 1: down since', $sent);
        $this->assertStringContainsString('2 hosting accounts', $sent);
        $this->assertStringContainsString('a server (its name is not given): planned maintenance', $sent);
        foreach (['nl1-internal', 'secret-host', 'hidden-name', 'fine'] as $private) {
            $this->assertStringNotContainsString($private, $sent);
        }
    }

    public function test_the_writing_buttons_follow_their_switches(): void
    {
        $this->actingAs(User::factory()->create());
        config(['gaiacp.ai.features.outage' => false]);

        Livewire::test(ClientAreaSettings::class)
            ->assertActionHidden(TestAction::make('aiWrite')->schemaComponent('client_area.incident_message', 'form'))
            ->assertActionVisible(TestAction::make('aiImprove_fix')->schemaComponent('client_area.incident_message', 'form'));

        Livewire::test(AiSettings::class)
            ->assertSchemaStateSet(fn (array $state) => ['ai' => ['features' => ['domains' => false, 'products' => true] + $state['ai']['features']] + $state['ai']])
            ->fillForm(['ai' => ['features' => ['domains' => true]]])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertTrue(config('gaiacp.ai.features.domains'));
    }

    /** .gr and .com sold; RDAP knows taken.gr's neighbours, DNS says the rest has no nameservers. */
    private function domainSearch(): void
    {
        Registrar::factory()->withTlds()->create(['name' => 'Email Notifications', 'module' => 'email', 'credentials' => null]);
        Tld::query()->whereNotIn('tld', ['.gr', '.com'])->delete();
        Http::fake([
            DomainSearch::BOOTSTRAP_URL => Http::response(['services' => []]),
            'api.anthropic.com/v1/messages' => Http::response([
                'type' => 'message', 'role' => 'assistant', 'model' => 'claude-opus-5-5', 'stop_reason' => 'end_turn',
                'content' => [['type' => 'text', 'text' => "1. kafesbar.gr\n- kafes-online\nkafes\nmy cafe\nkafeneio"]],
                'usage' => ['input_tokens' => 60, 'output_tokens' => 20],
            ]),
        ]);
        DomainSearch::resolveNameserversUsing(fn (string $domain) => in_array($domain, ['kafes.gr', 'kafes.com', 'kafeneio.gr'], true) ? ['ns1.host.gr'] : []);
        $client = Client::factory()->create();
        Filament::setCurrentPanel('client');
        $this->actingAs(User::factory()->create(['role' => UserRole::Client, 'client_id' => $client->id, 'email' => $client->email]), 'client');
    }

    public function test_a_client_gets_free_names_close_to_a_taken_one(): void
    {
        config(['gaiacp.ai.features.domains' => true]);
        $this->domainSearch();

        $page = Livewire::test(SearchDomains::class)
            ->set('query', 'kafes.gr')
            ->call('search')
            ->assertSee(__('Suggest other names'))
            ->call('suggest')
            ->assertSee(__('Other free names, suggested by AI:'));

        // The ending searched; "kafes" itself and the taken kafeneio.gr left out; "my cafe" is no name
        $this->assertSame(['kafesbar.gr', 'kafes-online.gr'], array_column($page->get('suggestions'), 'domain'));
        $page->call('add', 'kafesbar.gr')->assertNotified();

        $sent = $this->sentToClaude();
        $this->assertStringContainsString("<text>\nkafes\n</text>", $sent, 'only the name goes to the AI service');
        $this->assertStringContainsString('speaks Greek', $sent);
    }

    public function test_suggestions_are_off_by_default_and_limited_per_hour(): void
    {
        $this->domainSearch();

        Livewire::test(SearchDomains::class)->set('query', 'kafes')->call('search')
            ->assertDontSee(__('Suggest other names'))
            ->call('suggest')
            ->assertSet('suggestions', []);
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'anthropic'));

        config(['gaiacp.ai.features.domains' => true]);
        $page = Livewire::test(SearchDomains::class)->set('query', 'kafes')->call('search');
        foreach (range(1, SearchDomains::AI_PER_HOUR) as $press) {
            $page->call('suggest');
        }
        $page->set('suggestions', [])->call('suggest')->assertNotified(__('You have asked for suggestions often. Try again in an hour.'));
        $this->assertSame(SearchDomains::AI_PER_HOUR, AiUsage::query()->where('feature', 'domains')->count());
    }
}
