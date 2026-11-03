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

        // The code is in the page, the tree itself starts hidden and is never mentioned in the guide
        $this->get('/admin')->assertOk()
            ->assertSee('class="gcp-family-backdrop"', false)
            ->assertSee('ArrowUp', false)->assertSee('x-on:keydown.window="listen($event)"', false);

        $this->assertSame(10, count(About::SECRET));
        $this->assertStringNotContainsString('ArrowUp', file_get_contents(base_path('docs/admin-guide/en/01-getting-started.md')));
    }

    public function test_not_on_the_login_page_or_in_the_client_area(): void
    {
        $this->get('/admin/login')->assertOk()->assertDontSee('class="gcp-about', false)->assertDontSee('class="gcp-family-backdrop"', false);
        $this->get('/login')->assertOk()->assertDontSee('class="gcp-about', false)->assertDontSee('class="gcp-family-backdrop"', false);
    }

    public function test_the_surprise_is_gaias_family_tree(): void
    {
        $family = About::family();
        $members = array_merge(...array_column($family, 'members'));

        $this->assertSame('Chaos', $members[0]['name']);
        $this->assertSame('Gaia', $members[1]['name']);
        $this->assertContains('Zeus', array_column($members, 'name'));
        $this->assertCount(count($members), array_unique(array_column($members, 'icon')));

        $this->actingAs(User::factory()->create());
        $page = $this->get('/admin')->assertOk()->assertSee('class="gcp-family-backdrop"', false)
            ->assertSee(__('Gaia\'s family'))->assertSee('this.family = true', false)->assertDontSee('gcp-egg', false);

        foreach ($members as $member) {
            $page->assertSee(__($member['name']))->assertSee(__($member['job']));
        }

        // Every member but Gaia (the hexagon itself) has a pictogram on the hexagon
        $this->assertSame(count($members) - 1, substr_count($page->getContent(), '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"'));
    }
}
