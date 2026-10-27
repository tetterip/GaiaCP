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
use Plugins\UptimeRobot\RobotMonitors;
use Plugins\UptimeRobot\UptimeRobotPlugin;
use Plugins\UptimeRobot\UptimeRobotSettings;
use Tests\TestCase;

/** Servers linked to an UptimeRobot monitor: getMonitors (API v2) with a read-only key, down / up in the bell. */
class UptimeRobotMonitoringTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create();
        config(['gaiacp.uptime_robot.api_key' => 'ur123-secret']);
    }

    /** getMonitors' answer: pl1 (HTTP) at $pl1, nl1 (ping) up, a paused one. */
    private function answer(int $pl1 = 2): array
    {
        return [
            'stat' => 'ok',
            'pagination' => ['offset' => 0, 'limit' => 50, 'total' => 3],
            'monitors' => [
                ['id' => 777001, 'friendly_name' => 'pl1 Plesk', 'url' => 'https://pl1.example.com:8443/', 'type' => 1, 'status' => $pl1,
                    'custom_uptime_ratio' => '99.985-99.910-99.500', 'response_times' => [['datetime' => 1760000000, 'value' => 231]], 'average_response_time' => '250.1'],
                ['id' => 777002, 'friendly_name' => 'nl1', 'url' => 'nl1.example.com', 'type' => 3, 'status' => 2, 'custom_uptime_ratio' => '100.000-100.000-100.000'],
                ['id' => 777003, 'friendly_name' => 'old', 'url' => 'https://old.example.com', 'type' => 1, 'status' => 0],
            ],
        ];
    }

    private function server(array $attributes = []): Server
    {
        return Server::factory()->create(['name' => 'pl1', 'hostname' => 'pl1.example.com', 'monitor_module' => 'uptime_robot', 'monitor_ref' => '777001', ...$attributes]);
    }

    public function test_it_reads_get_monitors_with_the_api_key(): void
    {
        Http::fake(['api.uptimerobot.com/*' => Http::response($this->answer())]);

        $monitors = (new UptimeRobotPlugin)->monitors();

        $this->assertSame(['777001', '777002', '777003'], array_map('strval', array_keys($monitors)));
        $this->assertSame('pl1 Plesk', $monitors['777001']->name);
        $this->assertSame(MonitorStatus::Up, $monitors['777001']->status);
        $this->assertSame('pl1.example.com', $monitors['777001']->host);
        $this->assertSame(231, $monitors['777001']->responseMs);
        $this->assertEqualsWithDelta(0.99985, $monitors['777001']->uptime['1d'], 0.000001);
        $this->assertEqualsWithDelta(0.9991, $monitors['777001']->uptime['30d'], 0.000001);
        $this->assertEqualsWithDelta(0.995, $monitors['777001']->uptime['365d'], 0.000001);
        $this->assertSame('nl1.example.com', $monitors['777002']->host);
        $this->assertSame(MonitorStatus::Unknown, $monitors['777003']->status);
        $this->assertSame([], $monitors['777003']->uptime);
        Http::assertSent(fn (Request $request) => $request->url() === UptimeRobotPlugin::API_URL
            && $request['api_key'] === 'ur123-secret'
            && $request['custom_uptime_ratios'] === '1-30-365');
    }

    public function test_states_map(): void
    {
        $monitors = RobotMonitors::parse([
            ['id' => 1, 'friendly_name' => 'a', 'status' => 9],
            ['id' => 2, 'friendly_name' => 'b', 'status' => 8],
            ['id' => 3, 'friendly_name' => '', 'status' => 1],
        ]);

        $this->assertSame([MonitorStatus::Down, MonitorStatus::Pending, MonitorStatus::Pending], array_map(fn ($m) => $m->status, array_values($monitors)));
        $this->assertSame('3', $monitors['3']->name);
    }

    public function test_it_reads_every_page(): void
    {
        $page = fn (int $from, int $count) => ['stat' => 'ok', 'pagination' => ['offset' => $from, 'limit' => 50, 'total' => 60],
            'monitors' => array_map(fn (int $id) => ['id' => $id, 'friendly_name' => "m{$id}", 'status' => 2], range($from + 1, $from + $count))];
        Http::fake(['api.uptimerobot.com/*' => Http::sequence()->push($page(0, 50))->push($page(50, 10))]);

        $this->assertCount(60, (new UptimeRobotPlugin)->monitors());
        Http::assertSentCount(2);
    }

    public function test_a_refused_key_is_a_clear_error(): void
    {
        Http::fake(['api.uptimerobot.com/*' => Http::response(['stat' => 'fail', 'error' => ['type' => 'invalid_parameter', 'parameter_name' => 'api_key', 'passed_value' => 'x', 'message' => 'api_key is invalid.']])]);
        $this->expectExceptionMessage('UptimeRobot refused the API key.');

        (new UptimeRobotPlugin)->monitors();
    }

    public function test_going_down_rings_the_bell_and_a_failing_api_is_logged_once(): void
    {
        $server = $this->server();
        Http::fake(['api.uptimerobot.com/*' => Http::sequence()
            ->push($this->answer(2))
            ->push($this->answer(9))
            ->push(['stat' => 'fail'], 500)
            ->push(['stat' => 'fail'], 500)]);

        $this->artisan('gaiacp:check-monitors')->assertSuccessful();
        $this->assertSame(MonitorStatus::Up, $server->fresh()->monitor_status);
        $this->assertEqualsWithDelta(0.9991, $server->fresh()->monitor_uptime['30d'], 0.000001);

        $this->artisan('gaiacp:check-monitors')->assertSuccessful();
        $this->assertSame(MonitorStatus::Down, $server->fresh()->monitor_status);
        $this->assertSame('Server pl1 is down', $this->admin->notifications()->first()->data['title']);

        $this->artisan('gaiacp:check-monitors')->assertFailed();
        $this->artisan('gaiacp:check-monitors')->assertFailed();
        $this->assertSame(MonitorStatus::Down, $server->fresh()->monitor_status);
        $this->assertSame(1, ActivityLog::query()->where('event', 'monitoring.failed')->count());
    }

    public function test_the_server_form_picks_the_monitor_checking_its_hostname(): void
    {
        $this->actingAs($this->admin);
        Http::fake(['api.uptimerobot.com/*' => Http::response($this->answer())]);
        $server = Server::factory()->create(['hostname' => 'nl1.example.com']);

        Livewire::test(EditServer::class, ['record' => $server->getRouteKey()])
            ->fillForm(['monitor_module' => 'uptime_robot'])
            ->assertFormSet(['monitor_ref' => '777002'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('777002', $server->fresh()->monitor_ref);
    }

    public function test_settings_keep_the_key_encrypted_and_test_connection_uses_what_is_typed(): void
    {
        $this->actingAs($this->admin);
        config(['gaiacp.uptime_robot.api_key' => null]);
        Http::fake(['api.uptimerobot.com/*' => Http::response($this->answer(9))]);

        Livewire::test(UptimeRobotSettings::class)
            ->assertFormSet(['uptime_robot.api_key' => null])
            ->fillForm(['uptime_robot' => ['api_key' => 'ur999-typed']])
            ->callAction('testConnection')
            ->assertNotified('Connected: 3 monitor(s)')
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertSet('data.uptime_robot.api_key', null);

        Http::assertSent(fn (Request $request) => $request['api_key'] === 'ur999-typed');
        $this->assertNotSame('ur999-typed', Setting::query()->find('uptime_robot.api_key')->value);
        $this->assertSame('ur999-typed', config('gaiacp.uptime_robot.api_key'));

        // Blank keeps the stored key.
        Livewire::test(UptimeRobotSettings::class)->fillForm(['uptime_robot' => ['api_key' => '']])->call('save')->assertHasNoFormErrors();
        $this->assertSame('ur999-typed', config('gaiacp.uptime_robot.api_key'));
    }
}
