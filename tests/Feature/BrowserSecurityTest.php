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
