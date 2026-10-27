<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Clusters\Settings\Pages\ClientAreaSettings;
use App\Filament\Clusters\Settings\Pages\CookieSettings;
use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Google Analytics (Settings → Cookies) and what search engines and link previews see (Settings → Client area). */
class SeoTest extends TestCase
{
    use RefreshDatabase;

    public function test_google_analytics_waits_for_accept_all(): void
    {
        $this->actingAs(User::factory()->create());
        Livewire::test(CookieSettings::class)
            ->set('data.cookies.google_analytics', 'not-an-id')
            ->call('save')
            ->assertHasErrors(['data.cookies.google_analytics'])
            ->set('data.cookies.banner', true)
            ->set('data.cookies.google_analytics', 'g-abc123xyz')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('G-ABC123XYZ', config('gaiacp.cookies.google_analytics'));
        auth()->logout();

        $this->get('/login')->assertOk()
            ->assertSee('<template id="gaiacp-consent-scripts"><script async src="https://www.googletagmanager.com/gtag/js?id=G-ABC123XYZ"></script>', false)
            ->assertSee("gtag('config', 'G-ABC123XYZ')", false);

        // Without the banner nobody can accept, so it never runs
        config(['gaiacp.cookies.banner' => false]);
        $this->get('/login')->assertOk()->assertDontSee('G-ABC123XYZ', false);
    }

    public function test_public_pages_have_a_description_and_previews_and_signed_in_pages_are_not_indexed(): void
    {
        config(['gaiacp.company.name' => 'Acme Hosting', 'gaiacp.seo.description' => 'Web hosting and domains in Greece.']);

        $this->get('/login')->assertOk()
            ->assertSee('<meta name="description" content="Web hosting and domains in Greece.">', false)
            ->assertSee('<meta property="og:title" content="Acme Hosting">', false)
            ->assertDontSee('noindex', false);

        config(['gaiacp.seo.index' => false]);
        $this->get('/login')->assertOk()->assertSee('<meta name="robots" content="noindex, nofollow">', false);

        config(['gaiacp.seo.index' => true]);
        $client = Client::factory()->create();
        $user = User::factory()->create(['role' => UserRole::Client, 'client_id' => $client->id, 'email' => $client->email]);
        $this->actingAs($user, 'client')->get('/dashboard')->assertOk()->assertSee('<meta name="robots" content="noindex, nofollow">', false);

        $this->get('/admin/login')->assertOk()->assertSee('<meta name="robots" content="noindex, nofollow">', false);
    }

    public function test_the_settings_save(): void
    {
        $this->actingAs(User::factory()->create());
        Livewire::test(ClientAreaSettings::class)
            ->set('data.seo.index', false)
            ->set('data.seo.description', 'Hosting in Athens.')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertFalse(config('gaiacp.seo.index'));
        $this->assertSame('Hosting in Athens.', config('gaiacp.seo.description'));
    }
}
