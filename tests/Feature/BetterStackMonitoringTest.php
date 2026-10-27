<?php

namespace Tests\Feature;

use App\Filament\Resources\Servers\Pages\EditServer;
use App\Models\ActivityLog;
use App\Models\Server;
use App\Models\Setting;
use App\Models\User;
use App\Monitoring\MonitorStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Plugins\BetterStack\BetterStackMonitors;
use Plugins\BetterStack\BetterStackPlugin;
use Plugins\BetterStack\BetterStackSettings;
use Tests\TestCase;

/** Servers linked to a Better Stack Uptime monitor: API v2 with a bearer token, down / up in the bell. */
class BetterStackMonitoringTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create();
        config(['gaiacp.better_stack.api_token' => 'bs-secret']);
    }

    /** pl1 (https) at $pl1, nl1 (ping) up, a paused one; $next names a next page. */
    private function answer(string $pl1 = 'up', ?string $next = null): array
    {
        return [
            'data' => [
                ['id' => '1001', 'type' => 'monitor', 'attributes' => ['url' => 'https://pl1.example.com:8443/', 'pronounceable_name' => 'pl1 Plesk', 'monitor_type' => 'status', 'status' => $pl1, 'paused' => false]],
                ['id' => '1002', 'type' => 'monitor', 'attributes' => ['url' => 'nl1.example.com', 'pronounceable_name' => 'nl1', 'monitor_type' => 'ping', 'status' => 'up', 'paused' => false]],
                ['id' => '1003', 'type' => 'monitor', 'attributes' => ['url' => 'https://old.example.com', 'pronounceable_name' => 'old', 'status' => 'paused', 'paused' => true]],
            ],
            'pagination' => ['first' => BetterStackPlugin::API_URL.'?page=1', 'last' => BetterStackPlugin::API_URL.'?page=1', 'prev' => null, 'next' => $next],
        ];
    }

    private function server(array $attributes = []): Server
    {
        return Server::factory()->create(['name' => 'pl1', 'hostname' => 'pl1.example.com', 'monitor_module' => 'better_stack', 'monitor_ref' => '1001', ...$attributes]);
    }

    public function test_it_reads_the_monitors_with_the_token(): void
    {
        Http::fake(['uptime.betterstack.com/*' => Http::response($this->answer())]);

        $monitors = (new BetterStackPlugin)->monitors();

        $this->assertSame([1001, 1002, 1003], array_keys($monitors)); // PHP keeps numeric refs as int keys
        $this->assertSame('pl1 Plesk', $monitors['1001']->name);
        $this->assertSame(MonitorStatus::Up, $monitors['1001']->status);
        $this->assertSame('pl1.example.com', $monitors['1001']->host);
        $this->assertSame('nl1.example.com', $monitors['1002']->host);
        $this->assertSame(MonitorStatus::Unknown, $monitors['1003']->status);
        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), BetterStackPlugin::API_URL)
            && $request->hasHeader('Authorization', 'Bearer bs-secret'));
    }

    public function test_states_map(): void
    {
        $monitors = BetterStackMonitors::parse([
            ['id' => 1, 'attributes' => ['pronounceable_name' => 'a', 'status' => 'down']],
            ['id' => 2, 'attributes' => ['pronounceable_name' => 'b', 'status' => 'maintenance']],
            ['id' => 3, 'attributes' => ['pronounceable_name' => 'c', 'status' => 'validating']],
            ['id' => 4, 'attributes' => ['url' => 'https://d.example.com', 'status' => 'pending']],
        ]);

        $this->assertSame([MonitorStatus::Down, MonitorStatus::Maintenance, MonitorStatus::Pending, MonitorStatus::Pending], array_map(fn ($m) => $m->status, array_values($monitors)));
        $this->assertSame('https://d.example.com', $monitors['4']->name);
    }

    public function test_it_follows_the_next_page_only_on_better_stack(): void
    {
        $second = ['data' => [['id' => '2001', 'attributes' => ['pronounceable_name' => 'm', 'status' => 'up']]], 'pagination' => ['next' => 'https://evil.example.com/steal']];
        Http::fake(['uptime.betterstack.com/*' => Http::sequence()->push($this->answer('up', BetterStackPlugin::API_URL.'?page=2'))->push($second)]);

        $this->assertCount(4, (new BetterStackPlugin)->monitors());
        Http::assertSentCount(2);
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'evil.example.com'));
    }

    public function test_a_refused_token_is_a_clear_error(): void
    {
        Http::fake(['uptime.betterstack.com/*' => Http::response(['errors' => 'Invalid Team API Token'], 401)]);
        $this->expectExceptionMessage('Better Stack refused the API token.');

        (new BetterStackPlugin)->monitors();
    }

    public function test_going_down_rings_the_bell_and_a_failing_api_is_logged_once(): void
    {
        $server = $this->server();
        Http::fake(['uptime.betterstack.com/*' => Http::sequence()
            ->push($this->answer('up'))
            ->push($this->answer('down'))
            ->push(['errors' => [['detail' => 'Server Error']]], 500)
            ->push(['errors' => [['detail' => 'Server Error']]], 500)]);

        $this->artisan('gaiacp:check-monitors')->assertSuccessful();
        $this->assertSame(MonitorStatus::Up, $server->fresh()->monitor_status);

        $this->artisan('gaiacp:check-monitors')->assertSuccessful();
        $this->assertSame(MonitorStatus::Down, $server->fresh()->monitor_status);
        $this->assertSame('Server pl1 is down', $this->admin->notifications()->first()->data['title']);

        $this->artisan('gaiacp:check-monitors')->assertFailed();
        $this->artisan('gaiacp:check-monitors')->assertFailed();
        $this->assertSame(1, ActivityLog::query()->where('event', 'monitoring.failed')->count());
    }

    public function test_the_server_form_picks_the_monitor_checking_its_hostname(): void
    {
        $this->actingAs($this->admin);
        Http::fake(['uptime.betterstack.com/*' => Http::response($this->answer())]);
        $server = Server::factory()->create(['hostname' => 'nl1.example.com']);

        Livewire::test(EditServer::class, ['record' => $server->getRouteKey()])
            ->fillForm(['monitor_module' => 'better_stack'])
            ->assertFormSet(['monitor_ref' => '1002'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('1002', $server->fresh()->monitor_ref);
    }

    public function test_settings_keep_the_token_encrypted_and_test_connection_uses_what_is_typed(): void
    {
        $this->actingAs($this->admin);
        config(['gaiacp.better_stack.api_token' => null]);
        Http::fake(['uptime.betterstack.com/*' => Http::response($this->answer('down'))]);

        Livewire::test(BetterStackSettings::class)
            ->assertFormSet(['better_stack.api_token' => null])
            ->fillForm(['better_stack' => ['api_token' => 'bs-typed']])
            ->callAction('testConnection')
            ->assertNotified('Connected: 3 monitor(s)')
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertSet('data.better_stack.api_token', null);

        Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer bs-typed'));
        $this->assertNotSame('bs-typed', Setting::query()->find('better_stack.api_token')->value);
        $this->assertSame('bs-typed', config('gaiacp.better_stack.api_token'));

        Livewire::test(BetterStackSettings::class)->fillForm(['better_stack' => ['api_token' => '']])->call('save')->assertHasNoFormErrors();
        $this->assertSame('bs-typed', config('gaiacp.better_stack.api_token'));
    }
}
