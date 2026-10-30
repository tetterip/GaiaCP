<?php

namespace Tests\Feature;

use App\Actions\ClientAreaAccess;
use App\Enums\AdminArea;
use App\Enums\UserRole;
use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The welcome tour of both panels: by itself on the first visit only, then from the user menu → Tour. */
class WelcomeTourTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_new_admin_gets_it_once_then_from_the_menu(): void
    {
        $admin = User::factory()->create();

        $this->actingAs($admin)->get('/admin')->assertOk()
            ->assertSee('Welcome to GaiaCP')
            ->assertSee('open: true', false)
            ->assertSee('Open the setup guide');

        $this->assertNotNull($admin->refresh()->admin_tour_seen_at);
        $this->assertNull($admin->client_tour_seen_at);

        $this->actingAs($admin)->get('/admin')->assertOk()
            ->assertSee('open: false', false)
            ->assertSee('Welcome to GaiaCP')
            ->assertSee('open-welcome-tour', false);
    }

    public function test_an_admin_sees_only_the_parts_they_can_open(): void
    {
        User::factory()->create(); // the first admin is a super admin
        $admin = User::factory()->create(['super_admin' => false, 'admin_areas' => [AdminArea::Support->value]]);

        $this->actingAs($admin)->get('/admin')->assertOk()
            ->assertSee('Support tickets')
            ->assertDontSee('Each client\'s page holds')
            ->assertDontSee('GaiaCP sends a payment notice before each due date')
            ->assertDontSee('Open the setup guide');
    }

    public function test_a_client_gets_it_on_the_first_login(): void
    {
        config(['gaiacp.company.name' => 'Acme Hosting']);
        $user = $this->clientUser();

        $this->actingAs($user, 'client')->get('/dashboard')->assertOk()
            ->assertSee('Welcome to Acme Hosting')
            ->assertSee('Your services')
            ->assertSee('open: true', false);

        $this->assertNotNull($user->refresh()->client_tour_seen_at);
        $this->actingAs($user, 'client')->get('/dashboard')->assertOk()->assertSee('open: false', false);
    }

    public function test_an_admin_looking_as_the_client_does_not_use_it_up(): void
    {
        $admin = User::factory()->create();
        $user = $this->clientUser();

        $this->actingAs($admin)->actingAs($user, 'client')
            ->withSession([ClientAreaAccess::VIEWING_AS => $user->id])
            ->get('/dashboard')->assertOk()
            ->assertSee('open: false', false);

        $this->assertNull($user->refresh()->client_tour_seen_at);
    }

    public function test_the_client_tour_can_be_turned_off(): void
    {
        config(['gaiacp.client_area.welcome_tour' => false]);
        $user = $this->clientUser();

        $this->actingAs($user, 'client')->get('/dashboard')->assertOk()
            ->assertDontSee('Your services')
            ->assertDontSee('open-welcome-tour', false);

        $this->assertNull($user->refresh()->client_tour_seen_at);
    }

    private function clientUser(): User
    {
        $client = Client::factory()->create();

        return User::factory()->create(['role' => UserRole::Client, 'client_id' => $client->id, 'email' => $client->email]);
    }
}
