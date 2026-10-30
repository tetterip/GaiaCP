<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Clusters\Settings\Pages\ClientAreaSettings;
use App\Filament\Clusters\Settings\Pages\CookieSettings;
use App\Models\Announcement;
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
            ->assertSee('<meta property="og:title" content="Login - Acme Hosting">', false)
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

    public function test_each_language_of_a_public_page_has_its_own_address(): void
    {
        config(['gaiacp.languages.enabled' => ['en', 'el'], 'gaiacp.public_area.home' => true]);

        $this->get('/?page=2&utm_source=x')->assertOk()
            ->assertSee('<link rel="canonical" href="'.url('/').'?page=2">', false)
            ->assertSee('<link rel="alternate" hreflang="el" href="'.url('/').'?page=2&amp;lang=el">', false)
            ->assertSee('<link rel="alternate" hreflang="x-default" href="'.url('/').'?page=2">', false);

        // ?lang= shows the page in that language and keeps it for the visit
        $this->get('/?lang=el')->assertOk()->assertSee('Σύνδεση')
            ->assertSee('<link rel="canonical" href="'.url('/').'?lang=el">', false)
            ->assertSee('<meta property="og:locale" content="el">', false);
        $this->get('/login')->assertOk()->assertSee('Σύνδεση');
        $this->get('/?lang=xx')->assertOk()->assertSee('Σύνδεση');

        // Picking a language in the top bar goes back without the old ?lang=
        $this->from(url('/').'?lang=el&page=2')->get('/language/en')->assertRedirect(url('/').'?page=2');

        config(['gaiacp.languages.enabled' => ['en']]);
        $this->get('/login')->assertOk()->assertDontSee('hreflang', false);
    }

    public function test_the_sitemap_lists_the_public_pages_in_every_language(): void
    {
        config([
            'gaiacp.languages.enabled' => ['en', 'el'],
            'gaiacp.public_area.home' => true,
            'gaiacp.public_area.announcements' => true,
            'gaiacp.public_area.status' => true,
        ]);
        Announcement::query()->create(['slug' => 'new-servers', 'texts' => ['en' => ['title' => 'New servers', 'body' => 'Hi']], 'published_at' => now()->subDay()]);
        Announcement::query()->create(['slug' => 'for-clients', 'texts' => ['en' => ['title' => 'Clients', 'body' => 'Hi']], 'published_at' => now()->subDay(), 'clients_only' => true]);
        Announcement::query()->create(['slug' => 'tomorrow', 'texts' => ['en' => ['title' => 'Later', 'body' => 'Hi']], 'published_at' => now()->addDay()]);

        $xml = $this->get('/sitemap.xml')->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8')->getContent();

        $this->assertStringContainsString('<loc>'.url('/').'</loc>', $xml);
        $this->assertStringContainsString('<loc>'.url('/').'?lang=el</loc>', $xml);
        $this->assertStringContainsString('<loc>'.url('announcements/new-servers').'</loc>', $xml);
        $this->assertStringContainsString('<loc>'.url('status').'</loc>', $xml);
        $this->assertStringContainsString('hreflang="x-default"', $xml);
        $this->assertStringNotContainsString('for-clients', $xml);
        $this->assertStringNotContainsString('tomorrow', $xml);
        $this->assertStringNotContainsString('knowledgebase', $xml);
        $this->assertNotFalse(simplexml_load_string($xml));

        config(['gaiacp.seo.index' => false]);
        $this->get('/sitemap.xml')->assertNotFound();
    }

    public function test_robots_txt_points_to_the_sitemap_or_keeps_everyone_out(): void
    {
        $robots = $this->get('/robots.txt')->assertOk()->getContent();
        $this->assertStringContainsString("Disallow: /admin/\n", $robots);
        $this->assertStringContainsString('Sitemap: '.url('sitemap.xml'), $robots);
        $this->assertStringNotContainsString("Disallow: /\n", $robots);

        config(['gaiacp.seo.index' => false]);
        $robots = $this->get('/robots.txt')->assertOk()->getContent();
        $this->assertStringContainsString("Disallow: /\n", $robots);
        $this->assertStringNotContainsString('Sitemap', $robots);
    }
}
