<?php

namespace Tests\Feature;

use App\Filament\Pages\Plugins;
use App\Models\ActivityLog;
use App\Models\Setting;
use App\Models\User;
use App\Plugins\PluginInstaller;
use App\Plugins\PluginInstallException;
use App\Plugins\PluginManager;
use App\Plugins\PluginState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use ZipArchive;

class PluginInstallerTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    private string $work;

    /** A folder and class name no other test has loaded (PHP can't unload a class). */
    private string $name;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create(['password' => 'secret-password']));

        $this->work = storage_path('framework/testing/plugins-'.Str::random(8));
        $this->directory = $this->work.'/plugins';
        File::ensureDirectoryExists($this->directory);
        config(['gaiacp.plugins.directory' => $this->directory]);
        $this->app->instance(PluginManager::class, new PluginManager);

        $this->name = 'Demo'.Str::random(8);
        $this->name = preg_replace('/[^A-Za-z]/', 'x', $this->name);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->work);
        File::deleteDirectory(storage_path('app/private/plugins'));

        parent::tearDown();
    }

    private function plugins(): PluginManager
    {
        return app(PluginManager::class);
    }

    private function id(): string
    {
        return Str::kebab($this->name);
    }

    private function table(): string
    {
        return Str::snake($this->name).'_items';
    }

    /** @return array<string, string> the files of a working plugin, in its folder */
    private function pluginFiles(string $version = '1.0.0', array $manifest = []): array
    {
        $name = $this->name;
        $table = $this->table();

        return [
            'plugin.json' => json_encode($manifest + ['version' => $version, 'requires' => '0.1.0', 'class' => "Plugins\\{$name}\\{$name}Plugin"]),
            'CHANGELOG.md' => "# Changelog\n\n## [{$version}] - 2026-10-18\n\n- First version\n",
            "{$name}Plugin.php" => <<<PHP
                <?php

                namespace Plugins\\{$name};

                use App\\Plugins\\Plugin;

                class {$name}Plugin extends Plugin
                {
                    public function id(): string { return '{$this->id()}'; }
                    public function name(): string { return 'Demo plugin'; }
                    public function description(): string { return 'Shows that uploads work.'; }
                    public function category(): string { return 'Integrations'; }
                    public function settingKeys(): array { return ['gaiacp.demo.token']; }
                }
                PHP,
            'database/migrations/2026_10_18_000001_create_items.php' => <<<PHP
                <?php

                use Illuminate\\Database\\Migrations\\Migration;
                use Illuminate\\Database\\Schema\\Blueprint;
                use Illuminate\\Support\\Facades\\Schema;

                return new class extends Migration
                {
                    public function up(): void { Schema::create('{$table}', fn (Blueprint \$table) => \$table->id()); }
                    public function down(): void { Schema::dropIfExists('{$table}'); }
                };
                PHP,
        ];
    }

    /** @param  array<string, string>  $files  path in the zip => contents */
    private function zip(array $files, ?string $folder = null): string
    {
        $path = $this->work.'/'.Str::random(6).'.zip';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE);
        foreach ($files as $name => $contents) {
            $zip->addFromString(($folder !== null ? $folder.'/' : '').$name, $contents);
        }
        $zip->close();

        return $path;
    }

    public function test_a_zip_installs_a_disabled_plugin_and_runs_its_migrations(): void
    {
        $plugin = app(PluginInstaller::class)->install($this->zip($this->pluginFiles(), $this->name));

        $this->assertSame($this->id(), $plugin->id());
        $this->assertFileExists("{$this->directory}/{$this->name}/{$this->name}Plugin.php");
        $this->assertTrue(Schema::hasTable($this->table()));
        $this->assertSame(PluginState::Disabled, $this->plugins()->state($plugin));
        $this->assertSame('1.0.0', $this->plugins()->installed($plugin)->version);
        $this->assertSame('Plugin Demo plugin 1.0.0 installed', ActivityLog::query()->where('event', 'plugin.installed')->value('description'));
        $this->assertSame([], glob(storage_path('app/private/plugins/*')), 'the unpacked copy is cleaned up');

        // Found again on the next request, without being listed anywhere
        $this->app->instance(PluginManager::class, new PluginManager);
        $this->assertNotNull($this->plugins()->find($this->id()));
        $this->assertFalse($this->plugins()->bundled($this->plugins()->find($this->id())));

        Livewire::test(Plugins::class)
            ->assertSee(['Demo plugin', 'Uploaded', 'Bundled'])
            ->assertTableActionVisible('uninstall', $this->id())
            ->callTableAction('enable', $this->id());

        $this->assertTrue($this->plugins()->enabled($this->id()));
    }

    public function test_installing_keeps_the_app_cache_so_the_open_page_keeps_working(): void
    {
        Cache::put('kept', 'yes');

        app(PluginInstaller::class)->install($this->zip($this->pluginFiles(), $this->name));

        $this->assertSame('yes', Cache::get('kept'));
        Livewire::test(Plugins::class)
            ->mountTableAction('changelog', 'cpanel')
            ->assertMountedActionModalSee('cPanel / WHM changelog');
    }

    public function test_plugin_json_may_sit_at_the_top_of_the_zip(): void
    {
        $plugin = app(PluginInstaller::class)->install($this->zip($this->pluginFiles()));

        $this->assertFileExists("{$this->directory}/{$this->name}/plugin.json");
        $this->assertSame($this->id(), $plugin->id());
    }

    public function test_a_newer_zip_updates_the_plugin_and_an_older_or_same_one_is_refused(): void
    {
        $installer = app(PluginInstaller::class);
        $plugin = $installer->install($this->zip($this->pluginFiles(), $this->name));
        $this->plugins()->enable($plugin);

        try {
            $installer->install($this->zip($this->pluginFiles('1.0.0'), $this->name));
            $this->fail('the same version was installed again');
        } catch (PluginInstallException $e) {
            $this->assertSame('Demo plugin 1.0.0 is already installed. Upload a newer version to update it.', $e->getMessage());
        }

        $files = $this->pluginFiles('1.1.0');
        $files['database/migrations/2026_10_19_000001_add_name.php'] = str_replace(
            ["Schema::create('{$this->table()}', fn (Blueprint \$table) => \$table->id())", "Schema::dropIfExists('{$this->table()}')"],
            ["Schema::table('{$this->table()}', fn (Blueprint \$table) => \$table->string('name')->nullable())", "Schema::table('{$this->table()}', fn (Blueprint \$table) => \$table->dropColumn('name'))"],
            $files['database/migrations/2026_10_18_000001_create_items.php'],
        );
        $installer->install($this->zip($files, $this->name));

        $installed = $this->plugins()->installed($plugin);
        $this->assertSame(['1.1.0', '1.0.0'], [$installed->version, $installed->previous]);
        $this->assertTrue(Schema::hasColumn($this->table(), 'name'));
        $this->assertTrue($this->plugins()->enabled($plugin), 'an update keeps the switch');
        $this->assertStringContainsString('"version":"1.1.0"', File::get("{$this->directory}/{$this->name}/plugin.json"));
        $this->assertCount(1, File::directories(storage_path('app/private/plugins/previous')));
        $this->assertSame('Plugin Demo plugin 1.1.0 uploaded over 1.0.0', ActivityLog::query()->where('event', 'plugin.installed')->latest('id')->value('description'));
    }

    public function test_failed_migrations_leave_nothing_installed(): void
    {
        $files = $this->pluginFiles();
        $files['database/migrations/2026_10_18_000002_broken.php'] = "<?php\n\nuse Illuminate\\Database\\Migrations\\Migration;\n\nreturn new class extends Migration { public function up(): void { throw new RuntimeException('no luck'); } };\n";

        $this->expectExceptionMessage('no luck');
        try {
            app(PluginInstaller::class)->install($this->zip($files, $this->name));
        } finally {
            $this->assertDirectoryDoesNotExist("{$this->directory}/{$this->name}");
            $this->assertNull($this->plugins()->find($this->id()));
        }
    }

    /** @return array<string, array{0: callable(self): array<string, string>, 1: string}> */
    public static function refusedZips(): array
    {
        return [
            'a path outside its folder' => [fn (self $test) => ['../evil.php' => '<?php'] + $test->pluginFiles(), '../evil.php).'],
            'no plugin.json' => [fn (self $test) => array_diff_key($test->pluginFiles(), ['plugin.json' => 1]), 'The zip has no plugin.json at its top or in its one top folder.'],
            'a PHP error' => [fn (self $test) => ['Broken.php' => '<?php function ('] + $test->pluginFiles(), 'Broken.php has a PHP error on line 1'],
            'a class outside Plugins\\' => [fn (self $test) => $test->pluginFiles(manifest: ['class' => 'App\\Models\\User']), 'plugin.json must name the plugin\'s class'],
            'no version' => [fn (self $test) => $test->pluginFiles(version: 'latest'), 'plugin.json needs a "version" like 1.0.0.'],
            'a newer GaiaCP needed' => [fn (self $test) => $test->pluginFiles(manifest: ['requires' => '99.0.0']), 'This plugin needs GaiaCP 99.0.0 or later.'],
            'a bundled plugin' => [fn (self $test) => ['plugin.json' => json_encode(['version' => '9.0.0', 'class' => 'Plugins\\Cpanel\\CpanelPlugin']), 'CpanelPlugin.php' => '<?php'], 'This is a plugin bundled with GaiaCP'],
        ];
    }

    #[DataProvider('refusedZips')]
    public function test_a_zip_that_fails_a_check_installs_nothing(callable $files, string $message): void
    {
        try {
            app(PluginInstaller::class)->install($this->zip($files($this), $this->name));
            $this->fail('the zip was installed');
        } catch (PluginInstallException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }

        $this->assertSame([], File::directories($this->directory));
        $this->assertFalse(Schema::hasTable($this->table()));
        $this->assertFileDoesNotExist(storage_path('framework/testing/evil.php'));
    }

    public function test_a_symlink_in_the_zip_is_refused(): void
    {
        $path = $this->zip($this->pluginFiles(), $this->name);
        $zip = new ZipArchive;
        $zip->open($path);
        $zip->addFromString("{$this->name}/link", '/etc/passwd');
        $zip->setExternalAttributesName("{$this->name}/link", ZipArchive::OPSYS_UNIX, (0120777 << 16));
        $zip->close();

        $this->expectExceptionMessage("The zip has a symbolic link ({$this->name}/link)");
        app(PluginInstaller::class)->install($path);
    }

    public function test_uninstall_needs_the_plugin_off_and_deletes_data_only_when_asked(): void
    {
        $installer = app(PluginInstaller::class);
        $plugin = $installer->install($this->zip($this->pluginFiles(), $this->name));
        Setting::query()->create(['key' => 'gaiacp.demo.token', 'value' => 'x']);
        $this->plugins()->enable($plugin);

        Livewire::test(Plugins::class)->assertTableActionHidden('uninstall', $this->id());

        $this->plugins()->disable($plugin);
        Livewire::test(Plugins::class)
            ->callTableAction('uninstall', $this->id(), ['delete_data' => true])
            ->assertNotified('Demo plugin uninstalled');

        $this->assertDirectoryDoesNotExist("{$this->directory}/{$this->name}");
        $this->assertFalse(Schema::hasTable($this->table()));
        $this->assertFalse(Setting::query()->where('key', 'gaiacp.demo.token')->exists());
        $this->assertFalse(DB::table('plugins')->where('id', $this->id())->exists());
        $this->assertNull($this->plugins()->find($this->id()));
        $this->assertSame('Plugin Demo plugin 1.0.0 uninstalled with its data', ActivityLog::query()->where('event', 'plugin.uninstalled')->value('description'));
    }

    public function test_uninstall_keeps_tables_and_settings_by_default(): void
    {
        $plugin = app(PluginInstaller::class)->install($this->zip($this->pluginFiles(), $this->name));
        Setting::query()->create(['key' => 'gaiacp.demo.token', 'value' => 'x']);

        app(PluginInstaller::class)->uninstall($plugin);

        $this->assertDirectoryDoesNotExist("{$this->directory}/{$this->name}");
        $this->assertTrue(Schema::hasTable($this->table()));
        $this->assertTrue(Setting::query()->where('key', 'gaiacp.demo.token')->exists());
    }

    public function test_bundled_plugins_are_never_uninstalled(): void
    {
        $plesk = $this->plugins()->find('plesk');
        $this->plugins()->disable($plesk);

        $this->expectExceptionMessage('Plugins bundled with GaiaCP can\'t be uninstalled, only disabled.');
        app(PluginInstaller::class)->uninstall($plesk);
    }

    public function test_the_page_uploads_a_zip_after_the_password_and_trust_box(): void
    {
        $upload = UploadedFile::fake()->createWithContent('demo.zip', File::get($this->zip($this->pluginFiles(), $this->name)));

        Livewire::test(Plugins::class)
            ->callAction('upload', ['zip' => $upload, 'trusted' => false, 'password' => 'wrong'])
            ->assertHasActionErrors(['trusted', 'password']);
        $this->assertNull($this->plugins()->find($this->id()));

        Livewire::test(Plugins::class)
            ->callAction('upload', ['zip' => $upload, 'trusted' => true, 'password' => 'secret-password'])
            ->assertHasNoActionErrors()
            ->assertNotified('Demo plugin 1.0.0 installed');

        $this->assertNotNull($this->plugins()->find($this->id()));
        $this->assertSame([], glob(storage_path('app/private/plugin-uploads/*')), 'the uploaded zip is deleted');
    }

    public function test_uploads_can_be_turned_off(): void
    {
        config(['gaiacp.plugins.uploads' => false]);

        Livewire::test(Plugins::class)->assertActionHidden('upload');
        $this->expectExceptionMessage('Plugin uploads are turned off');
        app(PluginInstaller::class)->install($this->zip($this->pluginFiles(), $this->name));
    }

    public function test_a_plugin_folder_that_does_not_load_is_reported(): void
    {
        File::ensureDirectoryExists("{$this->directory}/Broken");
        File::put("{$this->directory}/Broken/plugin.json", '{"version": "1.0.0"}');
        $this->app->instance(PluginManager::class, new PluginManager);

        $this->assertSame(['Broken' => 'Plugins\\Broken\\BrokenPlugin was not found'], $this->plugins()->problems());
        Livewire::test(Plugins::class)->assertSee('plugins/Broken was not loaded: Plugins\\Broken\\BrokenPlugin was not found.');
    }
}
