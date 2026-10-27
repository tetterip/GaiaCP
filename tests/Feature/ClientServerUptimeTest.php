<?php

namespace Tests\Feature;

use App\Enums\ServiceStatus;
use App\Enums\UserRole;
use App\Filament\Client\Resources\Services\Pages\ListServices;
use App\Filament\Client\Resources\Services\Pages\ViewService;
use App\Models\Client;
use App\Models\Service;
use App\Models\User;
use App\Monitoring\MonitorStatus;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/** A hosting account's page in the client area shows its server's uptime (Settings → Client area → Hosting). */
class ClientServerUptimeTest extends TestCase
{
    use RefreshDatabase;

    private Service $hosting;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-24 12:10', 'Europe/Athens'));

        $client = Client::factory()->create();
        $user = User::factory()->create(['role' => UserRole::Client, 'client_id' => $client->id, 'email' => $client->email]);
        $this->hosting = Service::factory()->for($client)->create(['username' => 'maria', 'domain' => 'maria.gr', 'status' => ServiceStatus::Active]);
        $this->hosting->server->update(['name' => 'pl1-secret', 'monitor_module' => 'uptime_kuma', 'monitor_ref' => '1']);
        $this->hosting->server->forceFill(['monitor_uptime' => ['30d' => 0.99914]])->save(); // the monitoring service's own 30 days

        // Within the last 24 hours: 3 of 4 checks up
        foreach (['11:35' => 'up', '11:40' => 'down', '11:45' => 'up', '12:05' => 'up'] as $at => $status) {
            DB::table('server_monitor_checks')->insert(['server_id' => $this->hosting->server_id, 'status' => $status, 'checked_at' => CarbonImmutable::parse("2026-10-24 {$at}", 'Europe/Athens')->utc()]);
        }

        Filament::setCurrentPanel('client');
        $this->actingAs($user, 'client');
    }

    public function test_the_page_shows_the_servers_uptime_without_its_name(): void
    {
        Livewire::test(ViewService::class, ['record' => $this->hosting->getRouteKey()])
            ->assertSee('Server uptime')
            ->assertSee('Last 30 days')
            ->assertSee('99.91%')
            ->assertSee('Last 24 hours')
            ->assertSee('75%')
            ->assertSee('gcp-server-uptime', false)
            ->assertDontSee('pl1-secret');
    }

    public function test_it_can_be_switched_off_and_needs_a_monitor(): void
    {
        config(['gaiacp.client_area.hosting_uptime' => false]);
        Livewire::test(ViewService::class, ['record' => $this->hosting->getRouteKey()])->assertDontSee('Server uptime');

        config(['gaiacp.client_area.hosting_uptime' => true]);
        $this->hosting->server->update(['monitor_module' => null]);
        Livewire::test(ViewService::class, ['record' => $this->hosting->getRouteKey()])->assertDontSee('Server uptime');
    }

    public function test_the_list_shows_a_dot_with_the_servers_state_before_the_domain(): void
    {
        $this->hosting->server->forceFill(['monitor_status' => MonitorStatus::Up])->save();

        Livewire::test(ListServices::class)
            ->assertSee('gcp-live gcp-live-up', false)
            ->assertSee('Server online · 99.91% uptime in the last 30 days')
            ->assertSee('maria.gr')
            ->assertDontSee('pl1-secret');

        $this->hosting->server->forceFill(['monitor_status' => MonitorStatus::Down])->save();
        Livewire::test(ListServices::class)->assertSee('gcp-live gcp-live-down', false)->assertSee('Server not responding');

        // No dot when the switch is off or the state isn't known
        config(['gaiacp.client_area.hosting_uptime' => false]);
        Livewire::test(ListServices::class)->assertDontSee('gcp-live gcp-live', false)->assertSee('maria.gr');

        config(['gaiacp.client_area.hosting_uptime' => true]);
        $this->hosting->server->forceFill(['monitor_status' => MonitorStatus::Unknown])->save();
        Livewire::test(ListServices::class)->assertDontSee('gcp-live gcp-live', false);
    }
}
