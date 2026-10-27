<?php

namespace Tests\Feature;

use App\Filament\Pages\Dashboard;
use App\Filament\Resources\Servers\Pages\EditServer;
use App\Filament\Resources\Servers\Pages\ListServers;
use App\Models\ActivityLog;
use App\Models\Server;
use App\Models\User;
use App\Monitoring\MonitorStatus;
use App\Monitoring\UptimeHistory;
use App\Plugins\PluginManager;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Plugins\UptimeKuma\KumaMetrics;
use Plugins\UptimeKuma\UptimeKumaPlugin;
use Plugins\UptimeKuma\UptimeKumaSettings;
use Tests\TestCase;

/** Servers linked to an Uptime Kuma monitor: status read from /metrics, down / up in the bell, list and dashboard. */
class ServerMonitoringTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create();
        config(['gaiacp.uptime_kuma' => ['url' => 'https://kuma.example.com/dashboard', 'username' => null, 'password' => 'uk1_secret', 'verify_tls' => true]]);
    }

    /** Kuma 2's metrics page: pl1 at $pl1, nl1 up. */
    private function metrics(int $pl1 = 1): string
    {
        return <<<TXT
            # HELP monitor_status Monitor Status (1 = UP, 0= DOWN, 2= PENDING, 3= MAINTENANCE)
            # TYPE monitor_status gauge
            monitor_status{monitor_id="1",monitor_name="pl1 \"Plesk\"",monitor_type="http",monitor_url="https://pl1.example.com:8443/",monitor_hostname="null",monitor_port="null"} {$pl1}
            monitor_status{monitor_id="2",monitor_name="nl1",monitor_type="ping",monitor_url="https://",monitor_hostname="nl1.example.com",monitor_port="null"} 1
            monitor_uptime_ratio{monitor_id="1",monitor_name="pl1 \"Plesk\"",monitor_type="http",monitor_url="https://pl1.example.com:8443/",monitor_hostname="null",monitor_port="null",window="1d"} 0.99985
            monitor_uptime_ratio{monitor_id="1",monitor_name="pl1 \"Plesk\"",monitor_type="http",monitor_url="https://pl1.example.com:8443/",monitor_hostname="null",monitor_port="null",window="30d"} 0.9991
            # TYPE monitor_response_time gauge
            monitor_response_time{monitor_id="1",monitor_name="pl1 \"Plesk\"",monitor_type="http",monitor_url="https://pl1.example.com:8443/",monitor_hostname="null",monitor_port="null"} 123
            TXT;
    }

    /** @var array{0: string, 1: int}|null what the fake Kuma answers now (a later call changes it) */
    private ?array $answer = null;

    private function fakeKuma(string|int $body = 1, int $status = 200): void
    {
        if ($this->answer === null) {
            Http::fake(['kuma.example.com/metrics' => fn () => Http::response(...$this->answer)]);
        }

        $this->answer = [is_int($body) ? $this->metrics($body) : $body, $status];
    }

    private function server(array $attributes = []): Server
    {
        return Server::factory()->create(['name' => 'pl1', 'hostname' => 'pl1.example.com', 'monitor_module' => 'uptime_kuma', 'monitor_ref' => '1', ...$attributes]);
    }

    public function test_it_reads_kumas_metrics_with_the_api_key(): void
    {
        $this->fakeKuma();

        $monitors = (new UptimeKumaPlugin)->monitors();

        $this->assertEquals(['1', '2'], array_keys($monitors));
        $this->assertSame('pl1 "Plesk"', $monitors['1']->name);
        $this->assertSame(MonitorStatus::Up, $monitors['1']->status);
        $this->assertSame('pl1.example.com', $monitors['1']->host);
        $this->assertSame(123, $monitors['1']->responseMs);
        $this->assertSame(['1d' => 0.99985, '30d' => 0.9991], $monitors['1']->uptime);
        $this->assertSame([], $monitors['2']->uptime);
        $this->assertSame('nl1.example.com', $monitors['2']->host);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://kuma.example.com/metrics'
            && $request->header('Authorization')[0] === 'Basic '.base64_encode(':uk1_secret'));
    }

    public function test_kuma_1_monitors_are_known_by_name_and_states_map(): void
    {
        $monitors = KumaMetrics::parse(implode("\n", [
            'monitor_status{monitor_name="web",monitor_type="http",monitor_url="https://web.example.com",monitor_hostname="null",monitor_port="null"} 0',
            'monitor_status{monitor_name="db",monitor_type="port",monitor_url="https://",monitor_hostname="db.example.com",monitor_port="3306"} 2',
            'monitor_status{monitor_name="mail",monitor_type="ping",monitor_url="https://",monitor_hostname="mail.example.com",monitor_port="null"} 3',
        ]));

        $this->assertSame(['web', 'db', 'mail'], array_keys($monitors));
        $this->assertSame([MonitorStatus::Down, MonitorStatus::Pending, MonitorStatus::Maintenance], array_map(fn ($m) => $m->status, array_values($monitors)));
    }

    public function test_a_refused_key_or_another_page_is_a_clear_error(): void
    {
        $this->fakeKuma('Unauthorized', 401);
        $this->expectExceptionMessage('Uptime Kuma refused the API key.');

        (new UptimeKumaPlugin)->monitors();
    }

    public function test_going_down_and_back_up_is_logged_and_rings_the_bell_once(): void
    {
        $this->freezeSecond();
        $limited = User::factory()->limitedTo()->create();
        $server = $this->server();

        $this->fakeKuma(1);
        $this->artisan('gaiacp:check-monitors')->assertSuccessful();
        $this->assertSame(MonitorStatus::Up, $server->fresh()->monitor_status);
        $this->assertNotNull($server->fresh()->monitor_checked_at);
        $this->assertSame(0, ActivityLog::query()->count());

        $this->fakeKuma(0);
        $this->artisan('gaiacp:check-monitors')->assertSuccessful();
        $this->artisan('gaiacp:check-monitors')->assertSuccessful();
        $this->assertSame(MonitorStatus::Down, $server->fresh()->monitor_status);
        $this->assertSame(1, ActivityLog::query()->where('event', 'server.down')->count());
        $this->assertSame('Server pl1 is down', $this->admin->notifications()->first()->data['title']);
        $this->assertSame(0, $limited->notifications()->count());

        $this->travel(12)->minutes();
        $this->fakeKuma(1);
        $this->artisan('gaiacp:check-monitors')->assertSuccessful();
        $up = ActivityLog::query()->where('event', 'server.up')->sole();
        $this->assertSame(720, $up->properties['down_seconds']);
        $this->assertSame('Server pl1 is up again', $this->admin->notifications()->latest()->first()->data['title']);
        $this->assertSame(2, $this->admin->notifications()->count());
    }

    public function test_a_monitor_that_is_gone_is_unknown_and_pending_does_not_alert(): void
    {
        $gone = $this->server(['monitor_ref' => '99']);
        $nl1 = $this->server(['name' => 'nl1', 'monitor_ref' => '2']);

        $this->fakeKuma(2);
        $this->artisan('gaiacp:check-monitors')->assertSuccessful();

        $this->assertSame(MonitorStatus::Unknown, $gone->fresh()->monitor_status);
        $this->assertSame(MonitorStatus::Up, $nl1->fresh()->monitor_status);
        $this->assertSame(0, ActivityLog::query()->count());
    }

    public function test_an_unreachable_kuma_is_logged_once_and_statuses_stay(): void
    {
        $server = $this->server(['monitor_status' => MonitorStatus::Up]);
        $server->forceFill(['monitor_status' => MonitorStatus::Up])->save();

        $this->fakeKuma('<html>login</html>');
        $this->artisan('gaiacp:check-monitors')->assertFailed();
        $this->artisan('gaiacp:check-monitors')->assertFailed();

        $this->assertSame(MonitorStatus::Up, $server->fresh()->monitor_status);
        $this->assertSame(1, ActivityLog::query()->where('event', 'monitoring.failed')->count());
        $this->assertSame('Uptime Kuma could not be read', $this->admin->notifications()->first()->data['title']);

        $this->fakeKuma(1);
        $this->artisan('gaiacp:check-monitors')->assertSuccessful();
        $this->assertSame(1, ActivityLog::query()->where('event', 'monitoring.recovered')->count());
    }

    public function test_nothing_is_read_while_the_plugin_is_off(): void
    {
        $this->server();
        app(PluginManager::class)->disable(app(PluginManager::class)->find('uptime_kuma'));
        Http::fake();

        $this->artisan('gaiacp:check-monitors')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_the_server_form_picks_the_monitor_checking_its_hostname(): void
    {
        $this->actingAs($this->admin);
        $this->fakeKuma();
        $server = Server::factory()->create(['hostname' => 'nl1.example.com']);

        Livewire::test(EditServer::class, ['record' => $server->getRouteKey()])
            ->fillForm(['monitor_module' => 'uptime_kuma'])
            ->assertFormSet(['monitor_ref' => '2'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('2', $server->fresh()->monitor_ref);

        // Changing the monitor forgets the old status
        $server->forceFill(['monitor_status' => MonitorStatus::Down])->save();
        $server->update(['monitor_ref' => '1']);
        $this->assertNull($server->fresh()->monitor_status);
    }

    public function test_the_form_works_with_a_cache_that_stores_no_objects(): void
    {
        // Production caches in the database with serializable_classes off: objects come back incomplete.
        config(['cache.default' => 'database', 'cache.serializable_classes' => false]);
        $this->actingAs($this->admin);
        $this->fakeKuma();
        $server = Server::factory()->create(['hostname' => 'nl1.example.com']);
        $this->artisan('gaiacp:check-monitors')->assertSuccessful(); // fills the cache first, as the schedule does

        Livewire::test(EditServer::class, ['record' => $server->getRouteKey()])
            ->fillForm(['monitor_module' => 'uptime_kuma'])
            ->assertFormSet(['monitor_ref' => '2'])
            ->assertSee('nl1 · nl1.example.com')
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('2', $server->fresh()->monitor_ref);
    }

    public function test_each_check_is_kept_for_the_uptime_bar(): void
    {
        $this->freezeSecond();
        $server = $this->server();

        $this->fakeKuma(1);
        $this->artisan('gaiacp:check-monitors')->assertSuccessful();
        $this->travel(5)->minutes();
        $this->fakeKuma(0);
        $this->artisan('gaiacp:check-monitors')->assertSuccessful();

        $this->assertSame(['up', 'down'], DB::table('server_monitor_checks')->where('server_id', $server->id)->orderBy('id')->pluck('status')->all());
        $this->assertSame(['1d' => 0.99985, '30d' => 0.9991], $server->fresh()->monitor_uptime);

        // gaiacp:prune-logs drops what no bar shows any more
        $this->travel(UptimeHistory::KEEP_DAYS + 1)->days();
        $this->artisan('gaiacp:prune-logs')->assertSuccessful();
        $this->assertSame(0, DB::table('server_monitor_checks')->count());
    }

    public function test_the_bar_takes_each_slots_worst_status(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-18 12:10', 'Europe/Athens'));
        $server = $this->server();
        $check = fn (string $at, string $status) => DB::table('server_monitor_checks')->insert([
            'server_id' => $server->id, 'status' => $status, 'checked_at' => CarbonImmutable::parse($at, 'Europe/Athens')->utc(),
        ]);
        $check('2026-10-18 11:35', 'up');
        $check('2026-10-18 11:40', 'down');
        $check('2026-10-18 11:45', 'up');
        $check('2026-10-18 12:05', 'up');
        $check('2026-10-16 09:00', 'down');

        $day = UptimeHistory::for($server, '24h');
        $this->assertCount(24, $day['dots']);
        $this->assertSame('18/10 12:00–13:00', $day['dots'][23]['label']);
        $this->assertSame(MonitorStatus::Up, $day['dots'][23]['status']);
        $this->assertSame(MonitorStatus::Down, $day['dots'][22]['status']);
        $this->assertEqualsWithDelta(2 / 3, $day['dots'][22]['uptime'], 0.001);
        $this->assertNull($day['dots'][0]['status']);
        $this->assertSame('75%', UptimeHistory::percent($day['uptime'])); // 3 of 4 checks, no ratio from Kuma

        $month = UptimeHistory::for($server, '30d');
        $this->assertCount(30, $month['dots']);
        $this->assertSame('18/10/2026', $month['dots'][29]['label']);
        $this->assertSame(MonitorStatus::Down, $month['dots'][29]['status']);
        $this->assertSame(MonitorStatus::Down, $month['dots'][27]['status']);
        $this->assertNull($month['dots'][28]['status']);

        // Kuma's own ratio wins for the percentage
        $server->forceFill(['monitor_uptime' => ['1d' => 0.99985, '30d' => 0.9991]])->saveQuietly();
        $this->assertSame('99.98%', UptimeHistory::percent(UptimeHistory::for($server, '24h')['uptime']));
        $this->assertSame('99.91%', UptimeHistory::percent(UptimeHistory::for($server, '30d')['uptime']));
        $this->assertSame('100%', UptimeHistory::percent(1.0));
    }

    public function test_the_list_and_dashboard_show_the_status(): void
    {
        $this->actingAs($this->admin);
        $this->server()->forceFill(['monitor_status' => MonitorStatus::Down])->save();

        Livewire::test(ListServers::class)
            ->assertSee('Down')
            ->assertSee('Uptime, 24 hours')
            ->assertActionVisible('checkMonitors')
            ->set('uptimeRange', '30d')
            ->assertSee('Uptime, 30 days')
            ->assertSee(now()->format('d/m/Y').': not checked');

        $this->get(Dashboard::getUrl())->assertOk()->assertSee('gx-status-danger', false)->assertSee('gx-uptime', false);
    }

    public function test_settings_test_connection_uses_what_is_typed(): void
    {
        $this->actingAs($this->admin);
        config(['gaiacp.uptime_kuma.url' => null]);
        Http::fake(['kuma.other.com/metrics' => Http::response($this->metrics(0))]);

        Livewire::test(UptimeKumaSettings::class)
            ->fillForm(['uptime_kuma' => ['url' => 'https://kuma.other.com/', 'password' => 'uk2_new']])
            ->callAction('testConnection')
            ->assertNotified('Connected: 2 monitor(s)');

        Http::assertSent(fn (Request $request) => $request->header('Authorization')[0] === 'Basic '.base64_encode(':uk2_new'));
    }
}
