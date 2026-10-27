<?php

namespace Tests\Feature;

use App\Install\Installer;
use App\Models\Setting;
use App\Models\User;
use Dotenv\Dotenv;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class InstallerTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        // The installer's .env and lock file in a folder of their own, not this checkout's
        $this->dir = sys_get_temp_dir().'/gaiacp-install-'.uniqid();
        mkdir($this->dir);
        $this->app->useEnvironmentPath($this->dir);
        config(['gaiacp.install_lock' => $this->dir.'/installed']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);

        parent::tearDown();
    }

    private function installer(): Installer
    {
        return app(Installer::class);
    }

    private function freshDatabase(): void
    {
        $this->installer()->useDatabase(['driver' => 'sqlite', 'database' => $this->dir.'/gaiacp.sqlite']);
        $this->installer()->migrate();
    }

    public function test_an_existing_install_keeps_the_installer_closed_and_gets_its_lock(): void
    {
        file_put_contents($this->dir.'/.env', "APP_KEY=base64:abc\n");

        $this->get('/install')->assertNotFound();
        $this->assertFileExists($this->dir.'/installed');
        $this->get('/admin')->assertRedirect('/admin/login');
    }

    public function test_a_new_copy_goes_to_the_installer_which_writes_env_with_a_key(): void
    {
        $this->get('/admin')->assertRedirect('/install');
        $this->get('/')->assertRedirect('/install');

        $env = $this->installer()->readEnv();
        $this->assertStringStartsWith('base64:', $env['APP_KEY']);
        $this->assertSame('open', $env[Installer::MARKER]);
        $this->assertSame('production', $env['APP_ENV']);
        $this->assertSame('false', $env['APP_DEBUG']);

        $this->get('/install')->assertOk()
            ->assertSee('PHP version')
            ->assertSee('.env file')
            ->assertSee('comes with no warranty')
            ->assertSee('https://www.gnu.org/licenses/gpl-3.0.html', false)
            ->assertSee(route('install.database'))
            // The admin panel's look, light only, with the GaiaCP logo
            ->assertSee('<meta name="color-scheme" content="light">', false)
            ->assertSee('images/gaiacp/logo.svg', false)
            ->assertDontSee('prefers-color-scheme: dark', false);

        // A second visit keeps the key
        $this->get('/install')->assertOk();
        $this->assertSame($env['APP_KEY'], $this->installer()->readEnv()['APP_KEY']);
    }

    public function test_the_installer_speaks_greek_when_asked(): void
    {
        $this->get('/install?lang=el')->assertOk()->assertSee('Εγκατάσταση GaiaCP');
        $this->get('/install/database')->assertOk()->assertSee('Σύνδεση και δημιουργία πινάκων');
    }

    public function test_the_database_step_needs_the_server_details_for_mysql(): void
    {
        $this->get('/install');

        $this->post('/install/database', ['driver' => 'mysql'])->assertSessionHasErrors(['host', 'database', 'username']);
        $this->post('/install/database', ['driver' => 'oracle'])->assertSessionHasErrors('driver');
    }

    public function test_the_admin_step_waits_for_the_tables(): void
    {
        $this->installer()->prepareEnvironment(marker: true);
        $this->installer()->useDatabase(['driver' => 'sqlite', 'database' => $this->dir.'/empty.sqlite']);

        $this->get('/install/admin')->assertRedirect(route('install.database'));
    }

    public function test_finishing_creates_the_admin_saves_the_business_and_locks_the_installer(): void
    {
        $this->get('/install?lang=el');
        $this->freshDatabase();

        $this->get('/install/admin')->assertOk()->assertSee('Όνομα επιχείρησης');

        $this->post('/install/admin', [
            'company' => 'Acme Hosting',
            'company_email' => 'info@acme.gr',
            'url' => 'https://clients.example.com/',
            'name' => 'Alex',
            'email' => 'admin@acme.gr',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
            'timezone' => 'America/New_York',
            'date_format' => 'm/d/Y',
            'country' => 'US',
            'currency' => 'USD',
        ])->assertOk()->assertSee('schedule:run')->assertSee('https://clients.example.com/admin');

        $admin = User::where('email', 'admin@acme.gr')->firstOrFail();
        $this->assertTrue($admin->canAccessPanel(filament()->getPanel('admin')));
        $this->assertSame('el', $admin->locale);
        $this->assertSame('Acme Hosting', config('gaiacp.company.name'));
        $this->assertDatabaseHas('activity_logs', ['event' => 'install.completed']);

        $env = $this->installer()->readEnv();
        $this->assertArrayNotHasKey(Installer::MARKER, $env);
        $this->assertSame('Acme Hosting', $env['APP_NAME']);
        $this->assertSame('https://clients.example.com', $env['APP_URL']);
        $this->assertSame('USD', $env['GAIACP_CURRENCY']);
        $this->assertSame(['USD'], DB::table('currencies')->pluck('code')->all());
        $this->assertSame('America/New_York', Setting::query()->find('region.timezone')->value);
        $this->assertSame('m/d/Y', Setting::query()->find('region.date_format')->value);
        $this->assertSame('US', Setting::query()->find('region.country')->value);
        $this->assertSame('America/New_York', date_default_timezone_get());
        $this->assertSame($this->dir.'/gaiacp.sqlite', $env['DB_DATABASE']);
        $this->assertFileExists($this->dir.'/installed');

        $this->get('/install')->assertNotFound();
        $this->post('/install/admin', [])->assertNotFound();
    }

    public function test_the_admin_must_be_valid(): void
    {
        $this->get('/install');
        $this->freshDatabase();

        $this->post('/install/admin', ['company' => 'X', 'name' => 'A', 'email' => 'not-an-email', 'password' => 'short', 'password_confirmation' => 'other'])
            ->assertSessionHasErrors(['email', 'password']);
        $this->assertSame(0, User::count());
        $this->assertFileDoesNotExist($this->dir.'/installed');
    }

    public function test_the_command_installs_without_questions(): void
    {
        $this->artisan('gaiacp:install', [
            '--no-interaction' => true,
            '--db-connection' => 'sqlite',
            '--db-database' => $this->dir.'/cli.sqlite',
            '--company' => 'Test Co',
            '--url' => 'https://billing.example.com',
            '--admin-name' => 'Admin',
            '--admin-email' => 'cli@example.com',
            '--admin-password' => 'password1',
            '--timezone' => 'Europe/Berlin',
            '--country' => 'de',
        ])->expectsOutputToContain('https://billing.example.com/admin')
            ->expectsOutputToContain('schedule:run')
            ->assertSuccessful();

        $this->assertTrue(User::where('email', 'cli@example.com')->exists());
        $this->assertSame('Europe/Berlin', Setting::query()->find('region.timezone')->value);
        $this->assertSame('DE', Setting::query()->find('region.country')->value);
        $this->assertSame('d/m/Y', Setting::query()->find('region.date_format')->value);
        $this->assertSame(['EUR'], DB::table('currencies')->pluck('code')->all());
        $this->assertFileExists($this->dir.'/installed');
        $this->assertStringStartsWith('base64:', $this->installer()->readEnv()['APP_KEY']);

        $this->artisan('gaiacp:install', ['--no-interaction' => true])->expectsOutputToContain('already installed')->assertSuccessful();
    }

    public function test_env_values_survive_quoting(): void
    {
        $this->installer()->prepareEnvironment();
        $values = ['DB_PASSWORD' => 'p@ss w$rd"x', 'DB_USERNAME' => "it's \$HOME", 'APP_NAME' => 'Ταχύ Hosting', 'DB_HOST' => 'db.example.com'];
        $this->installer()->writeEnv($values);

        $parsed = Dotenv::parse((string) file_get_contents($this->dir.'/.env'));
        foreach ($values as $key => $value) {
            $this->assertSame($value, $parsed[$key], $key);
            $this->assertSame($value, $this->installer()->readEnv()[$key], $key);
        }

        // In place, not appended again; the commented-out example line is reused
        $this->assertSame(1, preg_match_all('/^DB_HOST=/m', (string) file_get_contents($this->dir.'/.env')));
        $this->assertSame(0, preg_match_all('/^# DB_HOST=/m', (string) file_get_contents($this->dir.'/.env')));
    }
}
