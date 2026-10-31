<?php

namespace Tests\Feature;

use App\Filament\Clusters\Settings\Pages\ClientAreaSettings;
use App\Filament\Clusters\Settings\Pages\GeneralSettings;
use App\Filament\Pages\Auth\EditProfile;
use App\Filament\Pages\Dashboard;
use App\Models\User;
use App\Plugins\PluginManager;
use App\Themes\Themes;
use Filament\Facades\Filament;
use Filament\Support\Colors\Color;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\TestCase;

class ThemesTest extends TestCase
{
    use RefreshDatabase;

    private function plugins(): PluginManager
    {
        return app(PluginManager::class);
    }

    public function test_gaia_is_the_default_and_brings_its_fonts_colours_and_stylesheet(): void
    {
        $this->assertSame(['gaia' => 'Gaia', 'flat' => 'Flat'], Themes::options());

        $this->actingAs(User::factory()->create());

        $this->get(Dashboard::getUrl())->assertOk()
            ->assertSee('fonts.bunny.net/css?family=commissioner', false)
            ->assertSee('fonts.bunny.net/css?family=literata', false)
            ->assertSee('--gaia-side', false)
            ->assertSee(Color::Zinc[500], false)
            ->assertDontSee(Color::Emerald[500], false);
    }

    public function test_the_login_page_has_the_default_theme_too(): void
    {
        $this->get(Filament::getPanel('admin')->getLoginUrl())->assertOk()
            ->assertSee('--gaia-side', false)
            // GaiaCP's own logo and tab icon, whatever the theme
            ->assertSee('images/gaiacp/logo.svg', false)
            ->assertSee('images/gaiacp/logo-dark.svg', false)
            ->assertSee('images/gaiacp/mark.svg', false);
    }

    public function test_the_header_logo_uses_the_same_hexagon_as_the_footer_mark(): void
    {
        $mark = file_get_contents(public_path('images/gaiacp/mark.svg'));
        preg_match('/<path d="([^"]+)"/', $mark, $hexagon);

        foreach (['logo.svg', 'logo-dark.svg'] as $file) {
            $logo = file_get_contents(public_path('images/gaiacp/'.$file));
            $this->assertStringContainsString($hexagon[1], $logo, $file);
            $this->assertStringNotContainsString('M256.81,125.25', $logo, $file.' still has the round globe');
        }
    }

    public function test_the_top_bar_buttons_share_one_gap(): void
    {
        $this->actingAs(User::factory()->create());

        // Filament pulls icon buttons (the bell, the cart) out by a negative margin; ours take it back to 0 so every
        // gap after the search is the same --gcp-topbar-gap
        $html = $this->get(Dashboard::getUrl())->assertOk()->getContent();

        $this->assertStringContainsString('column-gap: var(--gcp-topbar-gap)', $html);
        $this->assertStringContainsString('gap: var(--gcp-topbar-gap)', $html);
        $this->assertMatchesRegularExpression('/\.gcp-cart-icon \{[^}]*margin: 0;/', $html);
    }

    public function test_an_admin_who_picked_flat_gets_the_layout_in_emerald_without_shadows(): void
    {
        $this->actingAs(User::factory()->create(['theme' => 'flat']));

        $this->get(Dashboard::getUrl())->assertOk()
            ->assertSee('--theme-side', false) // the shared layout
            ->assertSee('--theme-shadow: 0 0 #0000', false)
            ->assertDontSee('--gaia-side', false)
            ->assertDontSee('fonts.bunny.net', false)
            ->assertSee(Color::Emerald[500], false);
    }

    public function test_profile_saves_the_admins_theme(): void
    {
        $this->actingAs($admin = User::factory()->create());

        Livewire::test(EditProfile::class)
            ->assertSee('Panel theme')
            ->assertSee('Default (Gaia)')
            ->set('data.theme', 'flat')
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertRedirect(EditProfile::getUrl());

        $this->assertSame('flat', $admin->fresh()->theme);
        $this->assertSame('flat', Themes::for($admin->fresh())->themeKey());
    }

    public function test_general_settings_sets_the_default(): void
    {
        $this->actingAs($admin = User::factory()->create());

        Livewire::test(GeneralSettings::class)
            ->assertSee('Default theme')
            ->fillForm(['theme' => ['default' => 'flat']])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('flat', config('gaiacp.theme.default'));
        $this->assertSame('flat', Themes::for($admin)->themeKey());
        $this->assertSame('gaia', Themes::for(new User(['theme' => 'gaia']))->themeKey());
    }

    public function test_a_theme_whose_plugin_is_off_falls_back(): void
    {
        $admin = User::factory()->create(['theme' => 'gaia']);
        $this->plugins()->disable($this->plugins()->find('gaia_theme'));

        // Neither the admin's pick nor the default (gaia) is on: the first theme that is
        $this->assertSame(['flat' => 'Flat'], Themes::options());
        $this->assertSame('flat', Themes::for($admin)->themeKey());

        // With one theme left Profile has nothing to pick
        $this->actingAs($admin);
        $this->get(EditProfile::getUrl())->assertOk()->assertDontSee('Panel theme');
    }

    public function test_with_every_theme_off_the_panel_keeps_filaments_look(): void
    {
        $this->plugins()->disable($this->plugins()->find('gaia_theme'));
        $this->plugins()->disable($this->plugins()->find('flat_theme'));

        $this->assertNull(Themes::for(null));
        $this->assertSame(['primary' => Color::Emerald], Themes::colors(null));

        $this->actingAs(User::factory()->create());
        $this->get(Dashboard::getUrl())->assertOk()
            ->assertDontSee('--gaia-side', false)
            ->assertSee(Color::Emerald[500], false);
    }

    private function clientLogin(): TestResponse
    {
        return $this->get(Filament::getPanel('client')->getLoginUrl());
    }

    public function test_the_client_area_defaults_to_gaia(): void
    {
        $this->clientLogin()->assertOk()
            ->assertSee('--gaia-side', false)
            ->assertSee('fonts.bunny.net/css?family=commissioner', false);
    }

    public function test_the_client_area_has_its_own_theme(): void
    {
        config(['gaiacp.client_area.theme' => 'flat']);

        // The admin panel keeps the admin's choice
        $this->actingAs(User::factory()->create());
        $this->get(Dashboard::getUrl())->assertOk()->assertSee('--gaia-side', false);

        auth()->logout();
        $this->clientLogin()->assertOk()
            ->assertSee('--theme-shadow: 0 0 #0000', false)
            ->assertDontSee('--gaia-side', false);
    }

    public function test_the_client_area_falls_back_when_its_theme_is_off(): void
    {
        config(['gaiacp.client_area.theme' => 'flat']);
        $this->plugins()->disable($this->plugins()->find('flat_theme'));

        $this->assertSame('gaia', Themes::forClientArea()->themeKey());
        $this->clientLogin()->assertOk()->assertSee('--gaia-side', false);

        $this->plugins()->disable($this->plugins()->find('gaia_theme'));

        $this->assertNull(Themes::forClientArea());
        $this->clientLogin()->assertOk()
            ->assertDontSee('--theme-side', false)
            ->assertDontSee('--gaia-side', false);
    }

    public function test_a_brand_colour_paints_the_client_area_only(): void
    {
        config(['gaiacp.client_area.brand_color' => '#D94F00']);

        $this->clientLogin()->assertOk()
            ->assertSee(Color::hex('#d94f00')[600], false)
            ->assertSee('--gaia-accent: var(--primary-700)', false);

        // The admin panel keeps its theme's colour
        Filament::setCurrentPanel('admin');
        $this->assertSame(Color::hex('#2C7A7C'), Themes::colors(Themes::find('gaia'))['primary']);
        Filament::setCurrentPanel('client');
        $this->assertSame(Color::hex('#d94f00'), Themes::colors(Themes::find('gaia'))['primary']);
    }

    public function test_client_area_settings_save_the_brand_colour(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(ClientAreaSettings::class)
            ->fillForm(['client_area.brand_color' => 'red'])
            ->call('save')
            ->assertHasFormErrors(['client_area.brand_color']);

        Livewire::test(ClientAreaSettings::class)
            ->fillForm(['client_area.brand_color' => '#1a73e8'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('#1a73e8', Themes::brandColor());
    }

    public function test_client_area_settings_save_the_theme(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(ClientAreaSettings::class)
            ->assertSee('Client area theme')
            ->fillForm(['client_area.theme' => 'flat'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('flat', config('gaiacp.client_area.theme'));
        $this->assertSame('flat', Themes::forClientArea()->themeKey());
    }

    public function test_client_area_settings_still_save_with_the_chosen_theme_off(): void
    {
        $this->actingAs(User::factory()->create());
        $this->plugins()->disable($this->plugins()->find('gaia_theme')); // the stored client_area.theme

        Livewire::test(ClientAreaSettings::class)
            ->fillForm(['client_area.maintenance' => true])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertTrue(config('gaiacp.client_area.maintenance'));

        $this->plugins()->disable($this->plugins()->find('flat_theme'));

        Livewire::test(ClientAreaSettings::class)
            ->fillForm(['client_area.maintenance' => false])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertFalse(config('gaiacp.client_area.maintenance'));
    }

    public function test_a_printed_payment_notice_is_dark_on_white_in_dark_mode_too(): void
    {
        $css = view('filament.themes.client')->render();
        $print = substr($css, strpos($css, '@media print'));

        $this->assertStringContainsString('--gcp-ink: #000', $print);
        $this->assertStringContainsString('--gcp-text: #000', $print);
    }
}
