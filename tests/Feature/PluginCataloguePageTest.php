<?php

namespace Tests\Feature;

use App\Filament\Pages\PluginCatalogue;
use App\Filament\Pages\Plugins;
use App\Models\ActivityLog;
use App\Models\User;
use App\Plugins\PluginManager;
use App\Plugins\PluginState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;
use ZipArchive;

class PluginCataloguePageTest extends TestCase
{
    use RefreshDatabase;

    private string $work;

    private string $secret;

    /** A folder and class name no other test has loaded (PHP can't unload a class). */
    private string $name;

    /** @var array<string, string> path => body the fake catalogue server answers with */
    private array $files = [];

    /** @var list<array<string, mixed>> the versions the catalogue offers */
    private array $versions = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create(['password' => 'secret-password']));

        $this->work = storage_path('framework/testing/catalogue-'.Str::random(8));
        File::ensureDirectoryExists($this->work.'/plugins');
        config(['gaiacp.plugins.directory' => $this->work.'/plugins']);
        $this->app->instance(PluginManager::class, new PluginManager);
        File::deleteDirectory(storage_path('app/private/plugins'));

        $this->name = preg_replace('/[^A-Za-z]/', 'x', 'Cat'.Str::random(8));

        $pair = sodium_crypto_sign_keypair();
        $this->secret = sodium_crypto_sign_secretkey($pair);
        config([
            'gaiacp.plugins.catalogue.enabled' => true,
            'gaiacp.plugins.catalogue.url' => 'https://plugins.example.test',
            'gaiacp.plugins.catalogue.keys' => [base64_encode(sodium_crypto_sign_publickey($pair))],
        ]);

        Http::fake(fn ($request) => isset($this->files[$path = parse_url($request->url(), PHP_URL_PATH)])
            ? Http::response($this->files[$path])
            : Http::response('', 404));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->work);
        File::deleteDirectory(storage_path('app/private/plugins'));

        parent::tearDown();
    }

    /** Adds a version to the catalogue, with its zip, and signs the catalogue again. */
    private function publish(string $version, bool $official = false, string $requires = '0.1.0'): void
    {
        $name = $this->name;
        $id = Str::kebab($name);
        $zipPath = $this->work.'/'.$version.'.zip';

        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString("{$name}/plugin.json", json_encode(['version' => $version, 'requires' => $requires, 'class' => "Plugins\\{$name}\\{$name}Plugin"]));
        $zip->addFromString("{$name}/CHANGELOG.md", "# Changelog\n\n## [{$version}] - 2026-10-25\n\n- Version {$version}\n");
        $zip->addFromString("{$name}/{$name}Plugin.php", <<<PHP
            <?php

            namespace Plugins\\{$name};

            use App\\Plugins\\Plugin;

            class {$name}Plugin extends Plugin
            {
                public function id(): string { return '{$id}'; }
                public function name(): string { return 'Catalogue plugin'; }
                public function description(): string { return 'From the catalogue.'; }
                public function category(): string { return 'Integrations'; }
            }
            PHP);
        $zip->close();

        $this->files["/zips/{$name}-{$version}.zip"] = file_get_contents($zipPath);
        $this->versions[] = [
            'version' => $version,
            'requires' => $requires,
            'url' => "zips/{$name}-{$version}.zip",
            'sha256' => hash_file('sha256', $zipPath),
            'size' => filesize($zipPath),
            'changelog' => "- Version {$version}",
        ];

        $json = json_encode([
            'format' => 1,
            'generated_at' => now()->addSeconds(count($this->versions))->toIso8601String(),
            'plugins' => [[
                'folder' => $name,
                'name' => 'Catalogue plugin',
                'description' => ['en' => 'From the catalogue.', 'el' => 'Από τον κατάλογο.'],
                'category' => 'Integrations',
                'author' => ['name' => 'Someone'],
                'licence' => 'GPL-3.0-or-later',
                'official' => $official,
                'versions' => $this->versions,
            ]],
        ]);

        $this->files['/catalogue.json'] = $json;
        $this->files['/catalogue.json.sig'] = base64_encode(sodium_crypto_sign_detached($json, $this->secret));
    }

    public function test_lists_the_catalogue_and_installs_a_plugin_disabled(): void
    {
        $this->publish('1.0.0');

        Livewire::test(PluginCatalogue::class)
            ->assertSee('Catalogue plugin')
            ->assertSee('Community')
            ->assertSee('Not installed')
            ->callTableAction('install', $this->name, ['password' => 'wrong'])
            ->assertHasTableActionErrors(['password']);

        $this->assertNull(app(PluginManager::class)->find(Str::kebab($this->name)));

        Livewire::test(PluginCatalogue::class)
            ->callTableAction('install', $this->name, ['password' => 'secret-password'])
            ->assertHasNoTableActionErrors()
            ->assertNotified('Catalogue plugin 1.0.0 installed')
            ->assertSee('Installed');

        $plugin = app(PluginManager::class)->find(Str::kebab($this->name));
        $this->assertSame('1.0.0', $plugin->version());
        $this->assertSame(PluginState::Disabled, app(PluginManager::class)->state($plugin));
        $this->assertSame([], File::files(storage_path('app/private/plugins/catalogue/downloads')), 'the zip is deleted');
    }

    public function test_updates_an_installed_plugin_and_rings_the_bell_once(): void
    {
        $other = User::factory()->create(['super_admin' => true]);
        $this->publish('1.0.0');
        Livewire::test(PluginCatalogue::class)->callTableAction('install', $this->name, ['password' => 'secret-password']);

        $this->publish('1.1.0');
        $me = auth()->user();
        auth()->logout(); // as cron runs it
        $this->artisan('gaiacp:check-plugin-catalogue')->expectsOutputToContain('1.0.0 → 1.1.0')->assertSuccessful();
        $this->artisan('gaiacp:check-plugin-catalogue')->expectsOutputToContain('told before')->assertSuccessful();

        $this->assertSame(1, ActivityLog::query()->where('event', 'plugin.update_available')->count());
        $this->assertSame(['Catalogue plugin 1.1.0 is out'], $other->notifications()->pluck('data')->pluck('title')->all());
        $this->actingAs($me);

        Livewire::test(Plugins::class)->assertSee('1.1.0 is in the plugin catalogue');

        Livewire::test(PluginCatalogue::class)
            ->assertSee('Update to 1.1.0')
            ->callTableAction('update', $this->name, ['password' => 'secret-password'])
            ->assertNotified('Catalogue plugin updated to 1.1.0');

        $this->assertSame('1.1.0', app(PluginManager::class)->find(Str::kebab($this->name))->version());
    }

    public function test_a_version_for_a_newer_gaiacp_is_not_offered(): void
    {
        $this->publish('1.0.0', requires: '99.0.0');

        Livewire::test(PluginCatalogue::class)
            ->assertSee('Needs GaiaCP 99.0.0')
            ->assertTableActionHidden('install', $this->name);
    }

    public function test_off_without_a_key_and_while_uploads_are_off(): void
    {
        $this->publish('1.0.0', official: true);

        config(['gaiacp.plugins.uploads' => false]);
        Livewire::test(PluginCatalogue::class)->assertSee('Official')->assertTableActionHidden('install', $this->name);

        config(['gaiacp.plugins.catalogue.keys' => []]);
        Livewire::test(PluginCatalogue::class)->assertSee('The plugin catalogue is turned off on this installation.');
        Livewire::test(Plugins::class)->assertActionHidden('catalogue');
        $this->artisan('gaiacp:check-plugin-catalogue')->expectsOutput('The plugin catalogue is off.')->assertSuccessful();
    }

    public function test_only_super_admins_open_it(): void
    {
        $this->actingAs(User::factory()->create(['super_admin' => false]));

        $this->get(PluginCatalogue::getUrl())->assertForbidden();
    }
}
