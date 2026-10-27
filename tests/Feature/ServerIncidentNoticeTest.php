<?php

namespace Tests\Feature;

use App\Enums\ServiceStatus;
use App\Enums\UserRole;
use App\Models\Client;
use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use App\Monitoring\MonitorStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The client area's notice while a server of the client's hosting is down or in maintenance. */
class ServerIncidentNoticeTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = Client::factory()->create();
        $this->user = User::factory()->create(['role' => UserRole::Client, 'client_id' => $this->client->id, 'email' => $this->client->email]);
    }

    private function hostingOn(?MonitorStatus $status, array $attributes = []): Service
    {
        $server = Server::factory()->create(['monitor_module' => 'uptime_kuma', 'monitor_ref' => '1']);
        $server->forceFill(['monitor_status' => $status])->save();

        return Service::factory()->for($this->client)->create(['server_id' => $server->id] + $attributes);
    }

    public function test_clients_on_a_down_server_see_the_notice_with_their_services(): void
    {
        $this->hostingOn(MonitorStatus::Down, ['domain' => 'maria.gr']);
        $this->hostingOn(MonitorStatus::Up, ['domain' => 'fine.gr']);

        $this->actingAs($this->user, 'client')->get('/dashboard')->assertOk()
            ->assertSee('Some of your services are not working right now')
            ->assertSee('maria.gr')
            ->assertSee('We know about it and are working to fix it.');
    }

    public function test_maintenance_has_its_own_title_and_the_message_can_be_changed(): void
    {
        config(['gaiacp.client_area.incident_message' => 'Back by 18:00.']);
        $this->hostingOn(MonitorStatus::Maintenance, ['domain' => 'maria.gr']);

        $this->actingAs($this->user, 'client')->get('/dashboard')->assertOk()
            ->assertSee('Maintenance on some of your services')
            ->assertSee('Back by 18:00.')
            ->assertDontSee('We know about it');
    }

    public function test_no_notice_when_up_hidden_cancelled_another_clients_or_switched_off(): void
    {
        $this->hostingOn(MonitorStatus::Up);
        $this->hostingOn(MonitorStatus::Down, ['client_visible' => false, 'domain' => 'hidden.gr']);
        $this->hostingOn(MonitorStatus::Down, ['status' => ServiceStatus::Cancelled, 'domain' => 'cancelled.gr']);
        $other = Server::factory()->create();
        $other->forceFill(['monitor_status' => MonitorStatus::Down])->save();
        Service::factory()->for(Client::factory())->create(['server_id' => $other->id, 'domain' => 'someone-else.gr']);

        $this->actingAs($this->user, 'client')->get('/dashboard')->assertOk()
            ->assertDontSee('Some of your services are not working right now');

        $this->hostingOn(MonitorStatus::Down, ['domain' => 'maria.gr']);
        config(['gaiacp.client_area.incident_notice' => false]);

        $this->actingAs($this->user, 'client')->get('/dashboard')->assertOk()
            ->assertDontSee('Some of your services are not working right now');
    }
}
