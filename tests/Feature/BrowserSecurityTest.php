<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
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
                ->assertHeaderContains('Content-Security-Policy', "frame-ancestors 'self'");
        }

        config(['gaiacp.security.csp' => 'off']);
        $this->get('/login')->assertHeader('Content-Security-Policy', "frame-ancestors 'self'");
    }

    public function test_scripts_run_only_with_the_pages_nonce(): void
    {
        $admin = User::factory()->create();

        foreach (['/admin/login', '/login', '/admin'] as $url) {
            if ($url === '/admin') {
                $this->actingAs($admin);
            }
            $response = $this->get($url)->assertOk();
            $policy = (string) $response->headers->get('Content-Security-Policy');
            $this->assertMatchesRegularExpression("/script-src 'nonce-([A-Za-z0-9]{40})' 'strict-dynamic' 'unsafe-eval'/", $policy);
            $this->assertStringContainsString("object-src 'none'", $policy);
            $this->assertStringContainsString("base-uri 'self'", $policy);
            $this->assertStringContainsString('report-uri /csp-report', $policy);

            // Every script tag on the page carries it (Filament's, Livewire's and GaiaCP's own), and no inline handlers
            preg_match("/'nonce-([A-Za-z0-9]+)'/", $policy, $match);
            preg_match_all('/<script\b(?![^>]*type="application\/ld\+json")[^>]*>/i', $response->getContent(), $tags);
            $this->assertNotEmpty($tags[0]);
            foreach ($tags[0] as $tag) {
                $this->assertStringContainsString('nonce="'.$match[1].'"', $tag, "{$url}: {$tag}");
            }
            $this->assertDoesNotMatchRegularExpression('/<[a-z][^>]*\son(click|change|load|error|submit|input)\s*=/i', $response->getContent(), $url);
        }
    }

    public function test_the_views_have_no_inline_event_handlers(): void
    {
        // The policy blocks onclick="" and the like; Alpine's x-on: or a script with @nonce does the same
        $files = collect([...glob(resource_path('views/**/*.blade.php')) ?: [], ...glob(resource_path('views/*/*/*.blade.php')) ?: [], ...glob(resource_path('views/*/*.blade.php')) ?: [], ...glob(base_path('plugins/*/views/*.blade.php')) ?: []])->unique();
        $this->assertGreaterThan(20, $files->count());

        foreach ($files as $file) {
            $this->assertDoesNotMatchRegularExpression('/<[a-z][^>]*\son[a-z]+\s*=\s*["\']/i', (string) file_get_contents($file), $file);
            $this->assertDoesNotMatchRegularExpression('/^\s*<script>/m', (string) file_get_contents($file), "{$file}: an inline script needs @nonce");
        }
    }

    public function test_on_report_browsers_only_report_and_the_reports_go_to_the_log(): void
    {
        config(['gaiacp.security.csp' => 'report']);

        $response = $this->get('/login')->assertOk()->assertHeader('Content-Security-Policy', "frame-ancestors 'self'");
        $this->assertStringContainsString("'strict-dynamic'", (string) $response->headers->get('Content-Security-Policy-Report-Only'));

        Log::spy();
        $this->call('POST', '/csp-report', [], [], [], ['CONTENT_TYPE' => 'application/csp-report'], json_encode(['csp-report' => [
            'document-uri' => 'https://clients.example.com/login', 'effective-directive' => 'script-src-elem', 'blocked-uri' => 'https://evil.example/x.js',
        ]]))->assertNoContent();
        Log::shouldHaveReceived('warning')->with('Content-Security-Policy would block', \Mockery::on(fn ($context) => $context['blocked'] === 'https://evil.example/x.js' && $context['directive'] === 'script-src-elem'))->once();

        // Junk is ignored
        $this->call('POST', '/csp-report', [], [], [], ['CONTENT_TYPE' => 'application/json'], 'nonsense')->assertNoContent();
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_a_page_with_a_policy_of_its_own_keeps_it(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('branding/logo.svg', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"/>');
        Settings::save(['company.logo' => 'branding/logo.svg']);

        $this->get('/branding/logo')->assertOk()->assertHeader('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; img-src data:; sandbox");
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
