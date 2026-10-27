<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Health\SystemHealth;
use App\Support\Version;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class VersionTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_version_comes_from_the_version_file(): void
    {
        $this->assertSame(trim(file_get_contents(base_path('VERSION'))), Version::current());
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+(-[0-9A-Za-z.-]+)?$/', Version::current());
    }

    public function test_the_changelog_has_a_section_for_the_version(): void
    {
        // bin/build-release.sh takes the release notes from it and refuses to build without one
        $this->assertStringContainsString('## ['.Version::current().'] - ', file_get_contents(base_path('CHANGELOG.md')));
    }

    public function test_signed_in_pages_show_the_version_and_the_login_page_does_not(): void
    {
        // Filament's asset URLs carry their own version numbers, so look for the footer's version tag
        $shown = '<span class="gcp-version">'.Version::current().'</span>';

        $this->get('/admin/login')->assertOk()->assertDontSee('<span class="gcp-version">', false);

        $this->actingAs(User::factory()->create());
        $this->get('/admin')->assertOk()->assertSeeInOrder(['Powered by', 'GaiaCP'])->assertSee($shown, false)
            ->assertSee('<span class="gcp-stage"', false);
    }

    public function test_it_says_how_far_along_gaiacp_is(): void
    {
        $this->assertSame('alpha', Version::STAGE);
        $this->assertSame('Alpha', Version::stageLabel());
        $this->assertStringContainsString('**Alpha.**', file_get_contents(base_path('README.md')));

        $this->get('/admin/login')->assertOk()->assertDontSee('<span class="gcp-stage"', false); // only signed in

        Artisan::call('about', ['--only' => 'gaiacp']);
        $this->assertStringContainsString('alpha', Artisan::output());
    }

    public function test_system_health_and_artisan_about_name_it(): void
    {
        $check = app(SystemHealth::class)->checks()->firstWhere('name', 'GaiaCP version');
        $this->assertSame(Version::current(), $check?->value);

        Artisan::call('about', ['--only' => 'gaiacp']);
        $this->assertStringContainsString(Version::current(), Artisan::output());
    }
}
