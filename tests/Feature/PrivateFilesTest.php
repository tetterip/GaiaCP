<?php

namespace Tests\Feature;

use App\Install\Installer;
use App\Support\Health\Status;
use App\Support\Health\SystemHealth;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Installs whose document root is GaiaCP's own folder, at a domain's root or in a subfolder: the root .htaccess,
 * the base path without "/public" (bootstrap/base-path.php) and the check that .env is not served.
 */
class PrivateFilesTest extends TestCase
{
    /**
     * @param  array<string, string>  $server
     * @return array{0: string, 1: string} SCRIPT_NAME and PHP_SELF after bootstrap/base-path.php
     */
    private function basePath(array $server): array
    {
        $saved = $_SERVER;
        $_SERVER = $server + ['PHP_SELF' => $server['SCRIPT_NAME']];

        try {
            require base_path('bootstrap/base-path.php');

            return [$_SERVER['SCRIPT_NAME'], $_SERVER['PHP_SELF']];
        } finally {
            $_SERVER = $saved;
        }
    }

    public function test_the_base_path_drops_public_only_when_the_address_has_none(): void
    {
        // Document root on GaiaCP's folder, at a domain's root and in a subfolder
        $this->assertSame(['/index.php', '/index.php'], $this->basePath(['SCRIPT_NAME' => '/public/index.php', 'REQUEST_URI' => '/admin/login']));
        $this->assertSame(['/billing/index.php', '/billing/index.php'], $this->basePath(['SCRIPT_NAME' => '/billing/public/index.php', 'REQUEST_URI' => '/billing/admin?x=1']));
        $this->assertSame(['/billing/index.php', '/billing/index.php'], $this->basePath(['SCRIPT_NAME' => '/billing/public/index.php', 'REQUEST_URI' => '/billing/']));

        // Document root on public/ (staging), and an address that does say /public/
        $this->assertSame(['/index.php', '/index.php'], $this->basePath(['SCRIPT_NAME' => '/index.php', 'REQUEST_URI' => '/admin']));
        $this->assertSame(['/billing/index.php', '/billing/index.php'], $this->basePath(['SCRIPT_NAME' => '/billing/index.php', 'REQUEST_URI' => '/billing/admin']));
        $this->assertSame(['/public/index.php', '/public/index.php'], $this->basePath(['SCRIPT_NAME' => '/public/index.php', 'REQUEST_URI' => '/public/admin']));
    }

    public function test_the_root_htaccess_sends_everything_to_public_and_hides_the_rest(): void
    {
        $htaccess = (string) file_get_contents(base_path('.htaccess'));

        $this->assertStringContainsString('RewriteRule ^(.*)$ public/$1 [L]', $htaccess);
        $this->assertStringContainsString('[R=404,L]', $htaccess);
        $this->assertStringContainsString('Require all denied', $htaccess); // without mod_rewrite
        $this->assertStringNotContainsString('/.htaccess export-ignore', (string) file_get_contents(base_path('.gitattributes')));
    }

    public function test_health_says_whether_env_is_served(): void
    {
        $health = app(SystemHealth::class);

        Http::fake(['https://safe.example/*' => Http::response('Not found', 404)]);
        $this->assertSame(Status::Ok, $health->privateFiles('https://safe.example/billing')->status);

        Http::fake([
            'https://open.example/billing/.env' => Http::response("APP_NAME=GaiaCP\nAPP_KEY=base64:secret\n"),
            'https://open.example/billing/composer.json' => Http::response('{"require": {"laravel/framework": "^13.0"}}'),
        ]);
        $check = $health->privateFiles('https://open.example/billing/');
        $this->assertSame(Status::Error, $check->status);
        $this->assertStringContainsString('.env, composer.json', $check->value);

        // A site that answers everything with its own page (200) does not count as serving them
        Http::fake(['https://spa.example/*' => Http::response('<html>Home</html>')]);
        $this->assertSame(Status::Ok, $health->privateFiles('https://spa.example')->status);

        Http::fake(['https://down.example/*' => fn () => throw new ConnectionException('refused')]);
        $this->assertSame(Status::Info, $health->privateFiles('https://down.example')->status);
    }

    public function test_the_installer_stops_while_env_would_be_served(): void
    {
        $dir = sys_get_temp_dir().'/gaiacp-install-'.uniqid();
        mkdir($dir);
        $this->app->useEnvironmentPath($dir);
        config(['gaiacp.install_lock' => $dir.'/installed']);

        try {
            Http::fake(['*/composer.json' => Http::response('{"require": {"laravel/framework": "^13.0"}}'), '*' => Http::response('', 404)]);

            $this->assertFalse(app(Installer::class)->meetsRequirements());
            $this->get('/install')->assertOk()->assertSee('Private files')->assertSee('Served to anyone: composer.json');
            $this->get('/install/database')->assertRedirect(route('install'));
        } finally {
            File::deleteDirectory($dir);
        }
    }
}
