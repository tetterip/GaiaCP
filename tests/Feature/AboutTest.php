<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\About;
use App\Support\Version;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AboutTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_footer_version_and_the_user_menu_open_about_gaiacp(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/admin')->assertOk()
            ->assertSee('class="gcp-about-open"', false)
            ->assertSee("\$dispatch('open-gaiacp-about')", false)
            ->assertSee('x-on:open-gaiacp-about.window', false)
            ->assertSee(__('About GaiaCP'))
            ->assertSee(__('Version :version', ['version' => Version::current()]))
            ->assertSee('PHP '.PHP_VERSION)
            ->assertSee('Laravel '.app()->version())
            ->assertSee(Version::ISSUES_URL, false);
    }

    public function test_it_lists_what_gaiacp_runs_on(): void
    {
        $stack = About::stack();

        $this->assertSame(['PHP', 'Laravel', 'Filament'], array_keys($stack));
        $this->assertMatchesRegularExpression('/^\d+\.\d+/', $stack['Filament']);
    }

    public function test_the_surprise_is_hidden_until_someone_finds_it(): void
    {
        $this->actingAs(User::factory()->create());

        // The code and the coins are in the page, the surprise itself starts hidden and is never mentioned in the guide
        $this->get('/admin')->assertOk()
            ->assertSee('class="gcp-egg"', false)
            ->assertSee('ArrowUp', false)->assertSee('x-on:keydown.window="listen($event)"', false)
            ->assertSee(__('Hello from Gaia!'));

        $this->assertSame(10, count(About::SECRET));
        $this->assertStringNotContainsString('ArrowUp', file_get_contents(base_path('docs/admin-guide/en/01-getting-started.md')));
    }

    public function test_the_coins_carry_the_base_currency(): void
    {
        config(['gaiacp.currency' => 'EUR']);
        $this->assertSame('€', About::coin());

        config(['gaiacp.currency' => 'USD']);
        $this->assertSame('$', About::coin());
    }

    public function test_not_on_the_login_page_or_in_the_client_area(): void
    {
        $this->get('/admin/login')->assertOk()->assertDontSee('class="gcp-about', false)->assertDontSee('class="gcp-egg"', false);
        $this->get('/login')->assertOk()->assertDontSee('class="gcp-about', false)->assertDontSee('class="gcp-egg"', false);
    }
}
