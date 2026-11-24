<?php

namespace Tests\Feature;

use App\Filament\Clusters\Settings\Pages\AutomationSettings;
use App\Filament\Widgets\ServerCapacity;
use App\Models\ActivityLog;
use App\Models\ModuleLog;
use App\Models\Server;
use App\Models\User;
use App\Monitoring\LoadHistory;
use App\Monitoring\ServerLoadCheck;
use App\Notifications\AdminAlerts;
use App\Plugins\PluginManager;
use App\Provisioning\ServerLoad;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Plugins\Virtualmin\VirtualminProvisioner;
use Tests\FakesCyberPanel;
use Tests\FakesDirectAdmin;
use Tests\FakesHestia;
use Tests\FakesPlesk;
use Tests\FakesProxmox;
use Tests\FakesVirtualmin;
use Tests\FakesWhm;
use Tests\TestCase;

class ServerLoadTest extends TestCase
{
    use FakesCyberPanel, FakesDirectAdmin, FakesHestia, FakesPlesk, FakesProxmox, FakesVirtualmin, FakesWhm, RefreshDatabase;

    private function check(): array
    {
        return app(ServerLoadCheck::class)->check();
    }

    private function latest(Server $server): ?object
    {
        return DB::table('server_load_readings')->where('server_id', $server->id)->orderByDesc('id')->first();
    }

    public function test_the_share_comes_from_the_load_and_the_cores_or_the_panels_own_percentage(): void
    {
        $this->assertSame(50.0, (new ServerLoad(2.0))->percent(4));
        $this->assertNull((new ServerLoad(2.0))->percent(), 'a load average without cores says nothing about the share');
        $this->assertSame(25.0, (new ServerLoad(1.0, 4))->percent(8), "the panel's own cores win");
        $this->assertSame(100.0, (new ServerLoad(12.0, 4))->percent(), 'processes waiting: shown as full');
        $this->assertSame(37.5, (new ServerLoad(null, 8, 37.5))->percent());
        $this->assertSame(0.15, ServerLoad::number('0.15 / 0.10 / 0.05'));
        $this->assertSame(1.5, ServerLoad::number('1,50'));
        $this->assertNull(ServerLoad::number(''));
    }

    public function test_cpanel_gives_the_load_and_the_cores_come_from_the_server_form(): void
    {
        $this->fakeWhm();
        $server = Server::factory()->create(['cpu_cores' => 3]);

        $this->assertSame(['servers' => 1, 'read' => 1, 'failed' => []], $this->check());
        $this->assertSame(50.0, (float) $this->latest($server)->percent);
        $this->assertSame(1.5, (float) $this->latest($server)->load);
        $this->assertSame('systemloadavg', $this->whmCalls[0][0]);
    }

    public function test_without_cores_a_load_average_is_kept_without_a_share(): void
    {
        $this->fakeWhm();
        $server = Server::factory()->create();

        $this->check();

        $this->assertNull($this->latest($server)->percent);
        $this->assertSame('—', LoadHistory::percent(LoadHistory::for($server)['now']) ?? '—');
    }

    public function test_plesk_gives_the_load_in_hundredths_and_its_cores(): void
    {
        $this->fakePlesk();
        $server = Server::factory()->plesk()->create();

        $this->check();

        $this->assertSame(37.5, (float) $this->latest($server)->percent); // 1.50 on 4 cores
        $this->assertSame(4, $server->fresh()->cpu_cores, 'filled in from the panel');
    }

    public function test_hestia_and_directadmin_give_the_load_average(): void
    {
        $this->fakeHestia();
        $hestia = Server::factory()->hestia()->create(['hostname' => 'hst.test', 'cpu_cores' => 1]);
        $this->assertSame(21.0, (float) app(PluginManager::class)->serverModule('hestia')->provisioner($hestia)->load()->percent(1));

        Http::clearResolvedInstances();
        $this->fakeDirectAdmin();
        $plugins = app(PluginManager::class);
        $plugins->enable($plugins->find('directadmin'));
        $da = Server::factory()->directAdmin()->create(['hostname' => 'da.test', 'cpu_cores' => 2]);
        $this->assertSame(40.0, $plugins->serverModule('directadmin')->provisioner($da)->load()->percent(2));
    }

    public function test_virtualmin_cyberpanel_and_proxmox(): void
    {
        $plugins = app(PluginManager::class);
        foreach (['virtualmin', 'cyberpanel', 'proxmox'] as $key) {
            $plugins->enable($plugins->find($key));
        }

        $this->fakeVirtualmin();
        $vm = Server::factory()->create(['type' => 'virtualmin', 'hostname' => 'vm.test', 'port' => 10000, 'username' => 'root', 'api_token' => $this->virtualminPassword]);
        $this->assertSame(30.0, $plugins->serverModule('virtualmin')->provisioner($vm)->load()->percent(), 'everything but idle');
        $this->assertSame(['program' => 'info', 'json' => '1', 'search' => 'cpu'], $this->virtualminCalls[0][1]);
        $this->assertNull(VirtualminProvisioner::busy("load: \n    * 0.1\n"));

        Http::clearResolvedInstances();
        $this->fakeCyberPanel();
        $cp = Server::factory()->create(['type' => 'cyberpanel', 'hostname' => 'cp.test', 'port' => 8090, 'username' => 'admin', 'api_token' => $this->cpPassword]);
        $load = $plugins->serverModule('cyberpanel')->provisioner($cp)->load();
        $this->assertSame(6, $load->cores);
        $this->assertSame(50.0, $load->percent());

        Http::clearResolvedInstances();
        $this->fakeProxmox();
        $pve = Server::factory()->create(['type' => 'proxmox', 'hostname' => 'pve.test', 'port' => 8006, 'username' => 'root@pam!gaiacp', 'api_token' => 'secret-uuid']);
        $load = $plugins->serverModule('proxmox')->provisioner($pve)->load();
        $this->assertSame(16, $load->cores, 'the online nodes only');
        $this->assertSame(50.0, $load->percent());
    }

    public function test_a_panel_that_cannot_be_read_is_skipped_and_only_its_failure_is_in_the_module_log(): void
    {
        config(['gaiacp.debug.module_log' => true]);
        $this->fakeWhm();
        $ok = Server::factory()->create(['name' => 'ok', 'cpu_cores' => 2]);
        $this->check();
        $this->assertSame(0, ModuleLog::query()->count(), 'a read that answered is not logged');

        $this->whmFailures['systemloadavg'] = 'Access denied';
        $result = $this->check();

        $this->assertSame(['ok: WHM systemloadavg: Access denied'], $result['failed']);
        $this->assertSame(1, DB::table('server_load_readings')->where('server_id', $ok->id)->count());
        $this->assertSame(0, ModuleLog::query()->count(), 'WHM answers a refusal with HTTP 200');
    }

    public function test_inactive_servers_and_switched_off_plugins_are_not_read(): void
    {
        $this->fakeWhm();
        Server::factory()->create(['active' => false]);
        Server::factory()->create(['type' => 'virtualmin']); // plugin off

        $this->assertSame(['servers' => 0, 'read' => 0, 'failed' => []], $this->check());
    }

    public function test_the_bell_rings_once_after_15_busy_minutes_and_the_calm_is_logged(): void
    {
        $this->fakeWhm();
        $admin = User::factory()->create();
        $server = Server::factory()->create(['name' => 'nl1', 'cpu_cores' => 1]); // 1.50 on 1 core = 100%

        $this->check();
        $this->travel(5)->minutes();
        $this->check();
        $this->assertSame(0, ActivityLog::query()->where('event', 'server.cpu_high')->count(), 'not after 10 minutes');

        $this->travel(5)->minutes();
        $this->check();
        $this->travel(5)->minutes();
        $this->check();

        $this->assertSame(1, ActivityLog::query()->where('event', 'server.cpu_high')->count(), 'once per busy spell');
        $this->assertNotNull($server->fresh()->cpu_alerted_at);
        $this->assertSame('Server nl1 is busy', $admin->notifications()->first()->data['title']);
        $this->assertSame('CPU at 100% for 15 minutes', $admin->notifications()->first()->data['body']);

        $server->forceFill(['cpu_cores' => 4])->save(); // 1.50 on 4 cores = 37.5%
        $this->travel(5)->minutes();
        $this->check();

        $this->assertNull($server->fresh()->cpu_alerted_at);
        $this->assertSame('Server nl1: CPU back to 37.5%', ActivityLog::query()->where('event', 'server.cpu_normal')->value('description'));
        $this->assertArrayHasKey('server.cpu_high', AdminAlerts::EVENTS);
        $this->assertArrayNotHasKey('server.cpu_normal', AdminAlerts::EVENTS);
    }

    public function test_no_bell_when_the_warning_is_off(): void
    {
        config(['gaiacp.automation.cpu_warning_percent' => null]);
        $this->fakeWhm();
        Server::factory()->create(['cpu_cores' => 1]);

        foreach (range(1, 4) as $i) {
            $this->check();
            $this->travel(5)->minutes();
        }

        $this->assertSame(0, ActivityLog::query()->where('event', 'server.cpu_high')->count());
    }

    public function test_the_warning_is_a_setting(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(AutomationSettings::class)
            ->set('data.automation.cpu_warning_percent', '80')
            ->call('save')
            ->assertHasNoErrors();
        $this->assertSame(80, config('gaiacp.automation.cpu_warning_percent'));

        Livewire::test(AutomationSettings::class)
            ->set('data.automation.cpu_warning_percent', '')
            ->call('save')
            ->assertHasNoErrors();
        $this->assertNull(config('gaiacp.automation.cpu_warning_percent'));
    }

    public function test_the_dashboard_shows_the_cpu_bar_per_hour(): void
    {
        $this->actingAs(User::factory()->create());
        $this->travelTo(now()->startOfHour()->addMinutes(30)); // the two readings 2 hours ago fall in the same hour
        $server = Server::factory()->create(['name' => 'nl1']);
        DB::table('server_load_readings')->insert([
            ['server_id' => $server->id, 'percent' => 20, 'load' => 0.8, 'checked_at' => now()->subHours(2)->utc()],
            ['server_id' => $server->id, 'percent' => 40, 'load' => 1.6, 'checked_at' => now()->subHours(2)->addMinutes(5)->utc()],
            ['server_id' => $server->id, 'percent' => 95, 'load' => 3.8, 'checked_at' => now()->subMinutes(3)->utc()],
        ]);

        $history = LoadHistory::for($server);
        $this->assertSame(95.0, $history['now']);
        $this->assertCount(24, $history['hours']);
        $this->assertSame(['average' => 30.0, 'highest' => 40.0], array_intersect_key($history['hours'][21], ['average' => 0, 'highest' => 0]));

        $html = Livewire::test(ServerCapacity::class)->html();
        $this->assertStringContainsString('CPU, 24 hours', $html);
        $this->assertStringContainsString('95%', $html);
        $this->assertStringContainsString('30% on average, highest 40%', $html);
        $this->assertStringContainsString('var(--danger-500)', $html);

        // A reading older than 15 minutes: the panel stopped answering
        $this->travel(20)->minutes();
        $this->assertNull(LoadHistory::for($server)['now']);
    }

    public function test_a_panel_without_a_load_reading_shows_no_cpu_bar(): void
    {
        $this->actingAs(User::factory()->create());
        $plugins = app(PluginManager::class);
        Server::factory()->create(['type' => 'missing-panel']);

        $this->assertStringNotContainsString('CPU, 24 hours', Livewire::test(ServerCapacity::class)->html());
        $this->assertNull($plugins->serverModule('missing-panel'));
    }

    public function test_old_readings_are_pruned(): void
    {
        $server = Server::factory()->create();
        DB::table('server_load_readings')->insert([
            ['server_id' => $server->id, 'percent' => 10, 'load' => 0.1, 'checked_at' => now()->subDays(3)->utc()],
            ['server_id' => $server->id, 'percent' => 10, 'load' => 0.1, 'checked_at' => now()->subHour()->utc()],
        ]);

        $this->artisan('gaiacp:prune-logs')->assertSuccessful();

        $this->assertSame(1, DB::table('server_load_readings')->count());
    }
}
