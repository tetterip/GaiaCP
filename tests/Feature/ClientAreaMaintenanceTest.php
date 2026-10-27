<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Clusters\Settings\Pages\ClientAreaSettings;
use App\Models\Client;
use App\Models\User;
use App\Plugins\PluginManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Client area maintenance mode (Settings → Client area, like WHMCS's): the client area closes, /admin doesn't. */
class ClientAreaMaintenanceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $clientUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['super_admin' => true]);
        $client = Client::factory()->create(['email' => 'maria@example.gr']);
        $this->clientUser = User::factory()->create(['role' => UserRole::Client, 'client_id' => $client->id, 'email' => 'maria@example.gr']);
    }

    private function maintenance(?string $message = null, ?string $redirect = null): void
    {
        config(['gaiacp.client_area.maintenance' => true, 'gaiacp.client_area.maintenance_message' => $message, 'gaiacp.client_area.maintenance_redirect' => $redirect]);
    }

    public function test_off_by_default(): void
    {
        $this->assertFalse(config('gaiacp.client_area.maintenance'));
        $this->get('/login')->assertOk()->assertDontSee('Client area under maintenance');
    }

    public function test_every_client_area_page_shows_the_message(): void
    {
        $this->maintenance();

        $this->get('/login')->assertStatus(503)->assertSee('Client area under maintenance')
            ->assertSee('We are carrying out maintenance on the client area. Please try again shortly.');
        $this->get('/password-reset/request')->assertStatus(503);
        $this->actingAs($this->clientUser, 'client')->get('/dashboard')->assertStatus(503);
    }

    public function test_the_admins_message_is_shown_as_plain_text(): void
    {
        $this->maintenance("Back at 18:00.\n<b>Thanks</b>");

        $this->get('/login')->assertStatus(503)
            ->assertSee('Back at 18:00.<br />', false)
            ->assertSee('&lt;b&gt;Thanks&lt;/b&gt;', false)
            ->assertDontSee('Please try again shortly');
    }

    public function test_a_redirect_address_sends_clients_there(): void
    {
        $this->maintenance(redirect: 'https://status.example.com');

        $this->get('/login')->assertRedirect('https://status.example.com');
        // Livewire's requests can't follow it: they get the page
        $this->withHeader('X-Livewire', '1')->get('/login')->assertStatus(503);
    }

    public function test_signed_in_admins_still_get_in_and_are_told(): void
    {
        $this->maintenance(redirect: 'https://status.example.com');

        $this->actingAs($this->admin) // /admin
            ->actingAs($this->clientUser, 'client') // "Log in as client"
            ->get('/dashboard')->assertOk()->assertSee('Maintenance mode is on: clients see the maintenance page.');
    }

    public function test_the_admin_panel_and_webhooks_are_not_affected(): void
    {
        $this->maintenance();

        $this->get('/admin/login')->assertOk();
        $this->postJson('/webhooks/stripe', ['type' => 'checkout.session.completed'])->assertStatus(400);
    }

    public function test_it_is_switched_on_in_client_area_settings(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(ClientAreaSettings::class)
            ->fillForm(['client_area' => ['maintenance' => true, 'maintenance_message' => ' Back soon ', 'maintenance_redirect' => 'https://status.example.com']])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue(config('gaiacp.client_area.maintenance'));
        $this->assertSame('Back soon', config('gaiacp.client_area.maintenance_message'));
        $this->assertSame('https://status.example.com', config('gaiacp.client_area.maintenance_redirect'));

        Livewire::test(ClientAreaSettings::class)
            ->fillForm(['client_area' => ['maintenance_redirect' => 'http://localhost/status']])
            ->call('save')
            ->assertHasFormErrors(['client_area.maintenance_redirect']);
    }

    public function test_the_page_takes_the_client_areas_theme(): void
    {
        $this->maintenance();

        $this->get('/login')->assertStatus(503)
            ->assertSee('--bg: #f4f4f5', false) // Gaia's page colour
            ->assertSee('family=commissioner', false);

        config(['gaiacp.client_area.theme' => 'flat']);
        $this->get('/login')->assertStatus(503)->assertDontSee('#f4f4f5', false)->assertDontSee('family=commissioner', false);

        // No theme on: the page's own colours
        $plugins = app(PluginManager::class);
        $plugins->disable($plugins->find('gaia_theme'));
        $plugins->disable($plugins->find('flat_theme'));
        $this->get('/login')->assertStatus(503)->assertSee('--bg: #f7f8f7', false)->assertDontSee('fonts.bunny.net', false);
    }
}
