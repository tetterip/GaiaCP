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
use Plugins\HetrixTools\HetrixMonitors;
use Plugins\HetrixTools\HetrixToolsPlugin;
use Plugins\HetrixTools\HetrixToolsSettings;
use Tests\TestCase;

/** Servers linked to a HetrixTools uptime monitor: API v3 with a bearer token, down / up in the bell. */
class HetrixToolsMonitoringTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create();
        config(['gaiacp.hetrix_tools.api_token' => 'ht-secret']);
    }

    /** The uptime monitors: pl1 (website) at $pl1, nl1 (ping) up, a paused one. */
    private function answer(string $pl1 = 'up'): array
    {
        return [
            'monitors' => [
                ['id' => 'a1b2c3', 'name' => 'pl1 Plesk', 'type' => 'website', 'target' => 'https://pl1.example.com:8443/', 'uptime_status' => $pl1,
                    'monitor_status' => 'active', 'uptime' => 99.91, 'locations' => ['nyc' => ['response_time' => 200], 'ams' => ['response_time' => 262]]],
                ['id' => 'd4e5f6', 'name' => 'nl1', 'type' => 'ping', 'target' => 'nl1.example.com', 'uptime_status' => 'up', 'monitor_status' => 'active', 'uptime' => '100'],
                ['id' => 'g7h8i9', 'name' => 'old', 'type' => 'website', 'target' => 'https://old.example.com', 'uptime_status' => 'down', 'monitor_status' => 'paused'],
            ],
            'meta' => ['pagination' => ['page' => 1, 'per_page' => 100, 'total' => 3, 'total_pages' => 1]],
        ];
    }

    private function server(array $attributes = []): Server
    {
        return Server::factory()->create(['name' => 'pl1', 'hostname' => 'pl1.example.com', 'monitor_module' => 'hetrix_tools', 'monitor_ref' => 'a1b2c3', ...$attributes]);
    }

    public function test_it_reads_the_uptime_monitors_with_the_token(): void
    {
        Http::fake(['api.hetrixtools.com/*' => Http::response($this->answer())]);

        $monitors = (new HetrixToolsPlugin)->monitors();

        $this->assertSame(['a1b2c3', 'd4e5f6', 'g7h8i9'], array_keys($monitors));
        $this->assertSame('pl1 Plesk', $monitors['a1b2c3']->name);
        $this->assertSame(MonitorStatus::Up, $monitors['a1b2c3']->status);
        $this->assertSame('pl1.example.com', $monitors['a1b2c3']->host);
        $this->assertSame(231, $monitors['a1b2c3']->responseMs);
        $this->assertEqualsWithDelta(0.9991, $monitors['a1b2c3']->uptime['30d'], 0.000001);
        $this->assertSame('nl1.example.com', $monitors['d4e5f6']->host);
        $this->assertSame(MonitorStatus::Unknown, $monitors['g7h8i9']->status);
        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), HetrixToolsPlugin::API_URL)
            && $request->hasHeader('Authorization', 'Bearer ht-secret'));
    }

    public function test_states_map(): void
    {
        $monitors = HetrixMonitors::parse([
            ['id' => 1, 'name' => 'a', 'uptime_status' => 'down'],
            ['id' => 2, 'name' => 'b', 'uptime_status' => 'maintenance'],
            ['id' => 3, 'name' => 'c', 'uptime_status' => 'up', 'monitor_status' => 'maintenance'],
            ['id' => 4, 'name' => '', 'status' => 'Online'],
        ]);

        $this->assertSame([MonitorStatus::Down, MonitorStatus::Maintenance, MonitorStatus::Maintenance, MonitorStatus::Up], array_map(fn ($m) => $m->status, array_values($monitors)));
        $this->assertSame('4', $monitors['4']->name);
    }

    public function test_it_reads_every_page(): void
    {
        $page = fn (int $number, int $from, int $count) => ['meta' => ['pagination' => ['page' => $number, 'total_pages' => 2]],
            'monitors' => array_map(fn (int $id) => ['id' => $id, 'name' => "m{$id}", 'uptime_status' => 'up'], range($from + 1, $from + $count))];
        Http::fake(['api.hetrixtools.com/*' => Http::sequence()->push($page(1, 0, 100))->push($page(2, 100, 10))]);

        $this->assertCount(110, (new HetrixToolsPlugin)->monitors());
        Http::assertSentCount(2);
    }

    public function test_a_refused_token_is_a_clear_error(): void
    {
        Http::fake(['api.hetrixtools.com/*' => Http::response(['message' => 'Unauthenticated.'], 401)]);
        $this->expectExceptionMessage('HetrixTools refused the API token.');

        (new HetrixToolsPlugin)->monitors();
    }

    public function test_going_down_rings_the_bell_and_a_failing_api_is_logged_once(): void
    {
        $server = $this->server();
        Http::fake(['api.hetrixtools.com/*' => Http::sequence()
            ->push($this->answer('up'))
            ->push($this->answer('down'))
            ->push(['message' => 'Server Error'], 500)
            ->push(['message' => 'Server Error'], 500)]);

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
        Http::fake(['api.hetrixtools.com/*' => Http::response($this->answer())]);
        $server = Server::factory()->create(['hostname' => 'nl1.example.com']);

        Livewire::test(EditServer::class, ['record' => $server->getRouteKey()])
            ->fillForm(['monitor_module' => 'hetrix_tools'])
            ->assertFormSet(['monitor_ref' => 'd4e5f6'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('d4e5f6', $server->fresh()->monitor_ref);
    }

    public function test_settings_keep_the_token_encrypted_and_test_connection_uses_what_is_typed(): void
    {
        $this->actingAs($this->admin);
        config(['gaiacp.hetrix_tools.api_token' => null]);
        Http::fake(['api.hetrixtools.com/*' => Http::response($this->answer('down'))]);

        Livewire::test(HetrixToolsSettings::class)
            ->assertFormSet(['hetrix_tools.api_token' => null])
            ->fillForm(['hetrix_tools' => ['api_token' => 'ht-typed']])
            ->callAction('testConnection')
            ->assertNotified('Connected: 3 monitor(s)')
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertSet('data.hetrix_tools.api_token', null);

        Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer ht-typed'));
        $this->assertNotSame('ht-typed', Setting::query()->find('hetrix_tools.api_token')->value);
        $this->assertSame('ht-typed', config('gaiacp.hetrix_tools.api_token'));

        Livewire::test(HetrixToolsSettings::class)->fillForm(['hetrix_tools' => ['api_token' => '']])->call('save')->assertHasNoFormErrors();
        $this->assertSame('ht-typed', config('gaiacp.hetrix_tools.api_token'));
    }
}
