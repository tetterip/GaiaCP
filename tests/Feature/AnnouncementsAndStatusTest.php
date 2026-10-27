<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Client\Pages\ServerStatus;
use App\Filament\Clusters\Settings\Pages\PublicAreaSettings;
use App\Filament\Resources\Announcements\Pages\CreateAnnouncement;
use App\Filament\Resources\Servers\Pages\EditServer;
use App\Models\Announcement;
use App\Models\Client;
use App\Models\Server;
use App\Models\User;
use App\Monitoring\MonitorStatus;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** The public area's announcements (Support → Announcements, with an RSS feed) and the servers' status page (Settings → Public area). */
class AnnouncementsAndStatusTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $user;

    private Announcement $public;

    private Announcement $clientsOnly;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['email' => 'admin@example.com']);
        $client = Client::factory()->create();
        $this->user = User::factory()->create(['role' => UserRole::Client, 'client_id' => $client->id, 'email' => $client->email]);

        $this->public = Announcement::create(['texts' => ['en' => ['title' => 'New data centre', 'body' => 'We **moved** to Frankfurt.']], 'published_at' => now()->subDay()]);
        $this->clientsOnly = Announcement::create(['texts' => ['en' => ['title' => 'Price list for clients', 'body' => 'Only for you.']], 'published_at' => now()->subHour(), 'clients_only' => true]);
        Announcement::create(['texts' => ['en' => ['title' => 'Draft news', 'body' => 'Not yet.']]]);
        Announcement::create(['texts' => ['en' => ['title' => 'Future news', 'body' => 'Later.']], 'published_at' => now()->addDay()]);
    }

    public function test_announcements_stay_for_clients_until_they_are_switched_on(): void
    {
        $this->assertSame('new-data-centre', $this->public->slug);
        $this->get('/announcements')->assertForbidden();
        $this->get('/announcements/feed')->assertNotFound();

        $this->actingAs($this->user, 'client')->get('/announcements')->assertOk()
            ->assertSeeInOrder(['Price list for clients', 'New data centre'])
            ->assertDontSee('Draft news')->assertDontSee('Future news');
        $this->get('/announcements/new-data-centre')->assertOk()->assertSee('<strong>moved</strong>', false);
        $this->get('/announcements/draft-news')->assertNotFound();
    }

    public function test_visitors_read_the_public_ones_and_their_feed(): void
    {
        config(['gaiacp.public_area.announcements' => true, 'gaiacp.public_area.home' => true]);

        $this->get('/announcements')->assertOk()->assertSee('New data centre')->assertDontSee('Price list for clients')->assertSee('/announcements/feed');
        $this->get('/announcements/new-data-centre')->assertOk()->assertSee('We');

        // One kept for clients asks for the login, which brings the visitor back to it
        $this->get('/announcements/'.$this->clientsOnly->slug)->assertRedirect(Filament::getPanel('client')->getLoginUrl());
        $this->assertStringEndsWith('/announcements/'.$this->clientsOnly->slug, session('url.intended'));

        $this->get('/announcements/feed')->assertOk()
            ->assertHeader('Content-Type', 'application/rss+xml; charset=UTF-8')
            ->assertSee('<title>New data centre</title>', false)
            ->assertDontSee('Price list for clients')->assertDontSee('Draft news');

        // The newest on the home page, and a line in the side menu
        $this->get('/')->assertOk()->assertSee(__('News'))->assertSee('New data centre')->assertSee(__('Announcements'));
    }

    public function test_admins_write_announcements(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(CreateAnnouncement::class)
            ->fillForm(['texts' => ['en' => ['title' => 'New data centre', 'body' => 'Second time.']], 'published_at' => now()])
            ->call('create')
            ->assertHasNoFormErrors();

        $announcement = Announcement::query()->latest('id')->first();
        $this->assertSame(['new-data-centre-2', $this->admin->id], [$announcement->slug, $announcement->created_by]);
    }

    public function test_the_status_page_shows_monitored_servers_by_their_public_name(): void
    {
        $this->get('/status')->assertForbidden();
        config(['gaiacp.public_area.status' => true]);

        Server::factory()->create(['name' => 'pl1', 'public_name' => 'Web server 1', 'hostname' => 'pl1.secret-host.example', 'ip_address' => '203.0.113.7', 'monitor_module' => 'uptime_kuma', 'monitor_ref' => '1'])
            ->forceFill(['monitor_status' => MonitorStatus::Up])->save();
        Server::factory()->create(['name' => 'nl2', 'monitor_module' => 'uptime_kuma', 'monitor_ref' => '2'])
            ->forceFill(['monitor_status' => MonitorStatus::Down, 'monitor_status_since' => now()->subMinutes(5)])->save();
        Server::factory()->create(['name' => 'hidden-box', 'monitor_module' => 'uptime_kuma', 'monitor_ref' => '3', 'on_status_page' => false]);
        Server::factory()->create(['name' => 'not-monitored']);
        Server::factory()->create(['name' => 'old-box', 'active' => false, 'monitor_module' => 'uptime_kuma', 'monitor_ref' => '4']);

        $this->get('/status')->assertOk()
            ->assertSee(__('Some servers are not working right now'))
            // The one that is down first
            ->assertSeeInOrder(['nl2', __('Down'), 'Web server 1', __('Up')])
            ->assertDontSee('pl1.secret-host.example')->assertDontSee('203.0.113.7')->assertDontSee('>pl1<', false)
            ->assertDontSee('hidden-box')->assertDontSee('not-monitored')->assertDontSee('old-box');

        // Clients see it too, and everything up reads so
        Server::query()->where('name', 'nl2')->update(['monitor_status' => MonitorStatus::Up]);
        Filament::setCurrentPanel('client');
        $this->actingAs($this->user, 'client');
        Livewire::test(ServerStatus::class)->assertSee(__('All systems operational'));
    }

    public function test_the_settings_and_the_server_form_save_the_new_switches(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(PublicAreaSettings::class)
            ->set('data.public_area.announcements', true)
            ->set('data.public_area.status', true)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertTrue(config('gaiacp.public_area.announcements'));
        $this->assertTrue(config('gaiacp.public_area.status'));

        $server = Server::factory()->create(['monitor_module' => 'uptime_kuma', 'monitor_ref' => '1']);
        $this->assertTrue($server->fresh()->on_status_page);

        Livewire::test(EditServer::class, ['record' => $server->id])
            ->fillForm(['public_name' => 'Web server 1'])
            ->call('save')
            ->fillForm(['on_status_page' => false])
            ->call('save');

        $server->refresh();
        $this->assertSame(['Web server 1', false], [$server->public_name, $server->on_status_page]);
    }
}
