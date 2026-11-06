<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Client;
use App\Models\User;
use App\Themes\Themes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** WCAG 2.4.7: Tab always shows where it is, whatever the theme (resources/views/filament/focus.blade.php). */
class FocusMarkTest extends TestCase
{
    use RefreshDatabase;

    private const MARK = 'outline: 2px solid var(--primary-600) !important;';

    public function test_every_admin_theme_keeps_the_focus_mark_after_its_own_styles(): void
    {
        $this->assertNotEmpty(Themes::all());

        foreach (array_keys(Themes::all()) as $theme) {
            $this->actingAs(User::factory()->create(['theme' => $theme]));

            $this->assertMarkComesLast($this->get('/admin')->assertOk()->getContent(), $theme);
        }
    }

    public function test_every_client_area_theme_keeps_the_focus_mark_and_so_do_the_sign_in_pages(): void
    {
        $client = Client::factory()->create(['email' => 'maria@example.gr']);
        $user = User::factory()->create(['role' => UserRole::Client, 'client_id' => $client->id, 'email' => $client->email]);

        foreach (array_keys(Themes::all()) as $theme) {
            config(['gaiacp.client_area.theme' => $theme]);

            $this->get('/login')->assertOk()->assertSee(self::MARK, false);
            $this->assertMarkComesLast($this->actingAs($user, 'client')->get('/dashboard')->assertOk()->getContent(), $theme);
            auth('client')->logout();
        }

        $this->get('/admin/login')->assertOk()->assertSee(self::MARK, false);
    }

    private function assertMarkComesLast(string $html, string $theme): void
    {
        $mark = strpos($html, self::MARK);
        $this->assertNotFalse($mark, "no focus mark with the {$theme} theme");
        $this->assertSame($mark, strrpos($html, self::MARK));

        // after the theme's own styles, so none of its rules can win over it
        $styles = Themes::find($theme)?->themeStyles();
        if ($styles !== null) {
            $end = substr(trim(view($styles)->render()), -120);
            $this->assertNotFalse(strpos($html, $end), "{$theme} styles not on the page");
            $this->assertGreaterThan(strpos($html, $end), $mark, "the {$theme} theme's styles come after the focus mark");
        }
    }
}
