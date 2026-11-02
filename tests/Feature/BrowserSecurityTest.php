<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** What the browser is told to keep GaiaCP's pages and cookies safe. */
class BrowserSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_other_site_shows_the_panels_in_a_frame(): void
    {
        User::factory()->create();

        foreach (['/admin/login', '/login'] as $url) {
            $this->get($url)->assertOk()
                ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
                ->assertHeader('Content-Security-Policy', "frame-ancestors 'self'");
        }
    }

    public function test_pages_keep_paths_from_other_sites_and_https_sticks(): void
    {
        User::factory()->create();

        $this->get('/admin/login')->assertOk()
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            // Plain http: HSTS would be ignored there and must not be sent
            ->assertHeaderMissing('Strict-Transport-Security');

        $this->get('https://localhost/admin/login')->assertOk()
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000');

        config(['gaiacp.security.hsts' => 0]);
        $this->get('https://localhost/admin/login')->assertOk()->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_the_session_cookie_is_secure_on_an_https_install(): void
    {
        $url = $_SERVER['APP_URL'] ?? null;
        $secure = $_SERVER['SESSION_SECURE_COOKIE'] ?? null;
        unset($_SERVER['SESSION_SECURE_COOKIE'], $_ENV['SESSION_SECURE_COOKIE']);

        try {
            $_SERVER['APP_URL'] = 'https://clients.example.com';
            $this->assertTrue((require config_path('session.php'))['secure']);

            $_SERVER['APP_URL'] = 'http://localhost:8000';
            $this->assertFalse((require config_path('session.php'))['secure']);
        } finally {
            if ($url === null) {
                unset($_SERVER['APP_URL']);
            } else {
                $_SERVER['APP_URL'] = $url;
            }
            if ($secure !== null) {
                $_SERVER['SESSION_SECURE_COOKIE'] = $secure;
            }
        }
    }
}
