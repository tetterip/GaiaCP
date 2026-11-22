<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Client;
use App\Models\User;
use App\Support\Version;
use App\Support\WhatsNew;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionProperty;
use Tests\TestCase;

/** What's new: a version's highlights, once per admin after an update, then from the user menu. */
class WhatsNewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->version('0.12.0'); // the version these tests' highlights belong to, whatever VERSION says now
    }

    protected function tearDown(): void
    {
        Version::forget();
        parent::tearDown();
    }

    public function test_an_admin_from_before_the_update_sees_it_once_then_from_the_menu(): void
    {
        $admin = $this->adminFromBefore();

        $this->actingAs($admin)->get('/admin')->assertOk()
            ->assertSee('data-whats-new-now="yes"', false)
            ->assertSee('New in 0.12.0')
            ->assertSee('Quarantine')
            ->assertSee('open-whats-new', false)
            ->assertSee('tab=domains', false);

        $this->assertSame('0.12.0', $admin->refresh()->whats_new_version);

        $this->actingAs($admin)->get('/admin')->assertOk()
            ->assertSee('data-whats-new-now="no"', false)
            ->assertSee('New in 0.12.0');
    }

    public function test_a_new_user_starts_at_the_current_version(): void
    {
        $admin = User::factory()->create();

        $this->assertSame(Version::current(), $admin->whats_new_version);
        $this->actingAs($admin)->get('/admin')->assertOk()->assertSee('data-whats-new-now="no"', false);
    }

    public function test_an_admin_who_has_not_had_the_tour_gets_the_tour_instead(): void
    {
        $admin = $this->adminFromBefore();
        $admin->forceFill(['admin_tour_seen_at' => null])->save();

        $this->actingAs($admin)->get('/admin')->assertOk()->assertSee('data-whats-new-now="no"', false);
        $this->assertSame('0.12.0', $admin->refresh()->whats_new_version);
    }

    public function test_a_version_without_highlights_shows_nothing(): void
    {
        $admin = $this->adminFromBefore();
        $this->version('0.1.0');

        $this->actingAs($admin)->get('/admin')->assertOk()->assertDontSee('open-whats-new', false);
        $this->assertNull($admin->refresh()->whats_new_version);
    }

    public function test_a_pre_release_shows_its_version(): void
    {
        $this->version('0.12.0-beta.1');

        $this->assertSame('0.12.0', WhatsNew::version());
        $this->assertNotEmpty(WhatsNew::highlights());
    }

    public function test_clients_never_see_it(): void
    {
        $client = Client::factory()->create();
        $user = User::factory()->create(['role' => UserRole::Client, 'client_id' => $client->id]);

        $this->actingAs($user, 'client')->get('/dashboard')->assertOk()->assertDontSee('open-whats-new', false);
    }

    private function adminFromBefore(): User
    {
        $admin = User::factory()->create();
        $admin->forceFill(['whats_new_version' => null, 'admin_tour_seen_at' => now()])->save();

        return $admin->fresh(); // as a request loads it
    }

    private function version(string $version): void
    {
        (new ReflectionProperty(Version::class, 'current'))->setValue(null, $version);
    }
}
