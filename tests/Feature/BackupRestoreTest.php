<?php

namespace Tests\Feature;

use App\Backups\RestoreBackup;
use App\Backups\RunBackup;
use App\Models\Client;
use App\Models\ModuleLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PDO;
use Tests\TestCase;
use ZipArchive;

/** Backups carry the files and, with a zip password, the APP_KEY; gaiacp:restore puts one back. */
class BackupRestoreTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    private string $originalStorage;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/gaiacp-restore-'.uniqid();
        $this->originalStorage = $this->app->storagePath();
        File::ensureDirectoryExists($this->dir.'/storage/framework');
        File::ensureDirectoryExists($this->dir.'/plugins');
        $this->app->useStoragePath($this->dir.'/storage');
        config(['gaiacp.backups.directory' => $this->dir.'/storage/app/private/backups', 'gaiacp.plugins.directory' => $this->dir.'/plugins']);
    }

    protected function tearDown(): void
    {
        $this->app->useStoragePath($this->originalStorage);
        File::deleteDirectory($this->dir);

        parent::tearDown();
    }

    private function file(string $path, string $contents = 'x'): void
    {
        File::ensureDirectoryExists(dirname($this->dir.'/'.$path));
        File::put($this->dir.'/'.$path, $contents);
    }

    /** @return array<string, string> entry => contents */
    private function entries(string $zipPath, ?string $password = null): array
    {
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($zipPath));
        $password && $zip->setPassword($password);
        $entries = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entries[$zip->getNameIndex($i)] = $zip->getFromIndex($i);
        }

        $zip->close();

        return $entries;
    }

    private function backup(): string
    {
        $this->artisan('gaiacp:backup')->assertSuccessful();

        return RunBackup::localBackups()[0];
    }

    /**
     * An empty database of the same engine on a connection of its own (`restore`), so the test's transaction and
     * tables are untouched: in memory for SQLite, a database next to the test one for MySQL / MariaDB.
     */
    private function restoreConnection(): callable
    {
        $config = DB::connection()->getConfig();

        if ($config['driver'] === 'sqlite') {
            config(['database.connections.restore' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]);

            return fn () => DB::purge('restore');
        }

        $name = $config['database'].'_restore';
        $pdo = new PDO("mysql:host={$config['host']};port={$config['port']}", $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec("DROP DATABASE IF EXISTS `{$name}`");
        $pdo->exec("CREATE DATABASE `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        config(['database.connections.restore' => ['database' => $name] + $config]);

        return function () use ($pdo, $name) {
            DB::purge('restore');
            $pdo->exec("DROP DATABASE IF EXISTS `{$name}`");
        };
    }

    public function test_the_backup_holds_the_uploaded_files_and_a_readme(): void
    {
        $this->file('storage/app/public/branding/logo.png', 'logo');
        $this->file('storage/app/private/tickets/7/invoice.pdf', 'pdf');
        $this->file('storage/app/private/updates/0.9.0/release.zip');
        $this->file('storage/app/private/plugins/previous/Old/plugin.json');
        $this->file('storage/app/private/installed');

        $entries = $this->entries($this->backup());
        $names = array_keys($entries);

        $this->assertStringEndsWith('.sql', $names[0], 'the dump stays the first entry');
        $this->assertSame('logo', $entries['files/storage/app/public/branding/logo.png']);
        $this->assertSame('pdf', $entries['files/storage/app/private/tickets/7/invoice.pdf']);
        $this->assertSame([], array_filter($names, fn ($name) => str_contains($name, '/updates/') || str_contains($name, '/plugins/previous/') || str_contains($name, '/backups/') || str_ends_with($name, '/installed')));
        $this->assertStringContainsString('gaiacp:restore', $entries[RunBackup::README_ENTRY]);
        $this->assertArrayNotHasKey(RunBackup::KEY_ENTRY, $entries, 'no APP_KEY in a zip without a password');
        $this->assertStringContainsString('The APP_KEY is not included', $entries[RunBackup::README_ENTRY]);

        config(['gaiacp.backups.include_files' => false]);
        File::deleteDirectory($this->dir.'/storage/app/private/backups');
        $this->assertSame([], array_filter(array_keys($this->entries($this->backup())), fn ($name) => str_starts_with($name, RunBackup::FILES_PREFIX)));
    }

    public function test_a_zip_with_a_password_carries_the_app_key_and_encrypts_every_entry(): void
    {
        config(['gaiacp.backups.zip_password' => 's3cret']);
        $this->file('storage/app/public/pages/a.png', 'a');
        $path = $this->backup();

        $zip = new ZipArchive;
        $zip->open($path);
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $this->assertNotSame(ZipArchive::EM_NONE, $zip->statIndex($i)['encryption_method'], $zip->getNameIndex($i).' is encrypted');
        }
        $zip->close();

        $this->assertSame(config('app.key')."\n", $this->entries($path, 's3cret')[RunBackup::KEY_ENTRY]);
    }

    public function test_a_backup_is_restored_with_its_files_and_app_key(): void
    {
        config(['gaiacp.backups.zip_password' => 's3cret']);
        Client::factory()->create(['first_name' => 'Νίκος', 'last_name' => "O'Brien", 'notes' => "line one;\nline two"]);
        $this->file('storage/app/private/tickets/7/invoice.pdf', 'pdf');
        $this->file('plugins/Mine/plugin.json', '{}'); // not loaded, so put in the zip by hand below
        $path = $this->backup();

        $zip = new ZipArchive;
        $zip->open($path);
        $zip->addFromString('files/plugins/Mine/plugin.json', '{"name": "Mine"}');
        $zip->close();

        File::delete($this->dir.'/storage/app/private/tickets/7/invoice.pdf');
        File::put($this->dir.'/.env', "APP_NAME=GaiaCP\nAPP_KEY=base64:other\n");
        $this->app->useEnvironmentPath($this->dir);
        $key = config('app.key');
        config(['app.key' => 'base64:other']);

        $cleanUp = $this->restoreConnection();

        try {
            $this->artisan('gaiacp:restore', ['file' => $path, '--password' => 's3cret', '--database' => 'restore', '--force' => true])
                ->expectsOutputToContain('APP_KEY in .env set from the backup')
                ->assertSuccessful();

            $restored = DB::connection('restore')->table('clients')->where('last_name', "O'Brien")->first();
            $this->assertSame('Νίκος', $restored->first_name);
            $this->assertSame("line one;\nline two", $restored->notes);
            $this->assertGreaterThan(0, DB::connection('restore')->table('migrations')->count());
        } finally {
            $cleanUp();
            $this->app->useEnvironmentPath(base_path());
        }

        $this->assertSame('pdf', File::get($this->dir.'/storage/app/private/tickets/7/invoice.pdf'));
        $this->assertSame('{"name": "Mine"}', File::get($this->dir.'/plugins/Mine/plugin.json'));
        $this->assertStringContainsString("APP_KEY={$key}\n", File::get($this->dir.'/.env'));
        $this->assertFalse($this->app->isDownForMaintenance());
        $this->assertDatabaseHas('activity_logs', ['event' => 'backup.restored']);
    }

    public function test_a_wrong_password_or_another_zip_changes_nothing(): void
    {
        config(['gaiacp.backups.zip_password' => 's3cret']);
        $path = $this->backup();

        $this->artisan('gaiacp:restore', ['file' => $path, '--password' => 'wrong', '--force' => true])
            ->expectsOutputToContain('The zip password is wrong')
            ->assertFailed();

        $this->artisan('gaiacp:restore', ['file' => $path, '--force' => true, '--no-interaction' => true])
            ->expectsOutputToContain('protected with a password')
            ->assertFailed();

        $other = $this->dir.'/other.zip';
        $zip = new ZipArchive;
        $zip->open($other, ZipArchive::CREATE);
        $zip->addFromString('readme.md', 'hello');
        $zip->close();

        $this->artisan('gaiacp:restore', ['file' => $other, '--force' => true])
            ->expectsOutputToContain('not a GaiaCP backup')
            ->assertFailed();

        $this->assertDatabaseMissing('activity_logs', ['event' => 'backup.restored']);
    }

    public function test_a_zip_writing_outside_the_gaiacp_folders_is_refused(): void
    {
        $path = $this->backup();
        $zip = new ZipArchive;
        $zip->open($path);
        $zip->addFromString('files/storage/app/private/../../../.env', 'APP_KEY=evil');
        $zip->close();

        $this->artisan('gaiacp:restore', ['file' => $path, '--force' => true])
            ->expectsOutputToContain('outside GaiaCP\'s folders')
            ->assertFailed();

        $this->assertFileDoesNotExist($this->dir.'/.env');
    }

    public function test_the_updaters_backup_keeps_the_logs_and_restores_only_the_database(): void
    {
        ModuleLog::create(['module' => 'cpanel', 'action' => 'createacct', 'url' => 'POST x', 'response' => 'ok', 'created_at' => now()]);
        $this->file('storage/app/public/branding/logo.png', 'logo');

        $result = app(RunBackup::class)(includeLogs: true, includeFiles: false);
        $entries = $this->entries($result->path);

        $this->assertStringContainsString('createacct', reset($entries));
        $this->assertSame([], array_filter(array_keys($entries), fn ($name) => str_starts_with($name, RunBackup::FILES_PREFIX)));

        File::delete($this->dir.'/storage/app/public/branding/logo.png');
        $cleanUp = $this->restoreConnection();

        try {
            (new RestoreBackup($result->path))->database('restore');
            $this->assertSame(1, DB::connection('restore')->table('module_logs')->count());
        } finally {
            $cleanUp();
        }

        $this->assertFileDoesNotExist($this->dir.'/storage/app/public/branding/logo.png');
    }
}
