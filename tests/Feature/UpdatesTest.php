<?php

namespace Tests\Feature;

use App\Filament\Pages\Updates;
use App\Models\User;
use App\Support\Version;
use App\Updates\GitHubReleases;
use App\Updates\Updater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

class UpdatesTest extends TestCase
{
    use RefreshDatabase;

    private const NEXT = '9.9.0';

    private string $dir;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/gaiacp-update-'.uniqid();
        $this->root = $this->dir.'/site';

        // An installed copy of the current version: its code, plus what is its own
        $this->file('VERSION', Version::current());
        $this->file('app/Old.php', '<?php // removed in the new release');
        $this->file('vendor/autoload.php', '<?php // old');
        $this->file('database/database.sqlite', 'the data');
        $this->file('database/migrations/old.php', '<?php');
        $this->file('plugins/Cpanel/Old.php', '<?php');
        $this->file('plugins/Mine/Plugin.php', '<?php // installed by the admin');
        $this->file('public/index.php', '<?php // old');
        $this->file('storage/app/public/logo.png', 'logo');
        $this->file('.env', 'APP_KEY=base64:abc');
        symlink($this->root.'/storage/app/public', $this->root.'/public/storage');

        config([
            'gaiacp.updates.root' => $this->root,
            'gaiacp.updates.token' => null,
            'gaiacp.updates.keys' => [], // the official key is set in the code; the signing tests set their own
            'gaiacp.backups.directory' => $this->dir.'/backups',
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);

        parent::tearDown();
    }

    private function file(string $path, string $contents): void
    {
        File::ensureDirectoryExists(dirname("{$this->root}/{$path}"));
        file_put_contents("{$this->root}/{$path}", $contents);
    }

    /** The release zip as bin/build-release.sh makes it. */
    private function releaseZip(?string $version = null): string
    {
        $version ??= self::NEXT;
        $zip = new ZipArchive;
        $path = "{$this->dir}/gaiacp-{$version}.zip";
        $zip->open($path, ZipArchive::CREATE);
        $files = [
            'VERSION' => $version."\n",
            'artisan' => '<?php',
            'README.md' => 'new readme',
            'app/New.php' => '<?php // new',
            'vendor/autoload.php' => '<?php // new',
            'database/migrations/new.php' => '<?php',
            'plugins/Cpanel/New.php' => '<?php',
            'public/index.php' => '<?php // new',
            'storage/app/.gitignore' => '*',
        ];
        foreach ($files as $name => $contents) {
            $zip->addFromString("gaiacp-{$version}/{$name}", $contents);
        }
        $zip->close();

        return (string) file_get_contents($path);
    }

    /** @param  string|null  $signedBy  a secret key: the checksum file gets a .sig asset signed with it */
    private function fakeGitHub(?string $zip = null, ?string $checksum = null, ?string $version = null, bool $withChecksum = true, ?string $signedBy = null, ?string $checksumName = null): void
    {
        $version ??= self::NEXT;
        $zip ??= $this->releaseZip($version);
        $checksumFile = ($checksum ?? hash('sha256', $zip)).'  '.($checksumName ?? "gaiacp-{$version}.zip")."\n";

        Http::fake([
            'api.github.com/repos/tetterip/GaiaCP/releases/latest' => Http::response([
                'tag_name' => "v{$version}",
                'body' => "- **Updates** from the panel\n- <script>alert(1)</script>Safe",
                'published_at' => '2026-10-11T08:00:00Z',
                'html_url' => "https://github.com/tetterip/GaiaCP/releases/tag/v{$version}",
                'assets' => [
                    ['name' => "gaiacp-{$version}.zip", 'url' => 'https://api.github.com/repos/tetterip/GaiaCP/releases/assets/1'],
                    ...($withChecksum ? [['name' => "gaiacp-{$version}.zip.sha256", 'url' => 'https://api.github.com/repos/tetterip/GaiaCP/releases/assets/2']] : []),
                    ...($signedBy !== null ? [['name' => "gaiacp-{$version}.zip.sha256.sig", 'url' => 'https://api.github.com/repos/tetterip/GaiaCP/releases/assets/3']] : []),
                ],
            ]),
            'api.github.com/repos/tetterip/GaiaCP/releases/assets/1' => Http::response($zip),
            'api.github.com/repos/tetterip/GaiaCP/releases/assets/2' => Http::response($checksumFile),
            'api.github.com/repos/tetterip/GaiaCP/releases/assets/3' => Http::response($signedBy !== null ? base64_encode(sodium_crypto_sign_detached($checksumFile, $signedBy))."\n" : ''),
        ]);
    }

    private function updater(): Updater
    {
        return app(Updater::class);
    }

    public function test_it_finds_a_newer_release(): void
    {
        $this->fakeGitHub();

        $release = $this->updater()->latest(fresh: true);

        $this->assertSame(self::NEXT, $release->version);
        $this->assertTrue($this->updater()->isNewer($release));

        // Cached for an hour
        $this->updater()->latest();
        Http::assertSentCount(1);
    }

    public function test_updating_swaps_the_code_and_keeps_what_is_the_installs_own(): void
    {
        $this->fakeGitHub();

        $this->updater()->update($this->updater()->latest(fresh: true));

        $this->assertSame(self::NEXT."\n", file_get_contents("{$this->root}/VERSION"));
        $this->assertFileExists("{$this->root}/app/New.php");
        $this->assertFileDoesNotExist("{$this->root}/app/Old.php");
        $this->assertSame('<?php // new', file_get_contents("{$this->root}/vendor/autoload.php"));
        $this->assertSame('<?php // new', file_get_contents("{$this->root}/public/index.php"));
        $this->assertFileExists("{$this->root}/plugins/Cpanel/New.php");
        $this->assertFileDoesNotExist("{$this->root}/plugins/Cpanel/Old.php");

        // The install's own: data, settings, uploads, its own plugin, the storage link
        $this->assertSame('the data', file_get_contents("{$this->root}/database/database.sqlite"));
        $this->assertFileExists("{$this->root}/database/migrations/new.php");
        $this->assertFileExists("{$this->root}/plugins/Mine/Plugin.php");
        $this->assertSame('APP_KEY=base64:abc', file_get_contents("{$this->root}/.env"));
        $this->assertSame('logo', file_get_contents("{$this->root}/storage/app/public/logo.png"));
        $this->assertTrue(is_link("{$this->root}/public/storage"));
        $this->assertSame('logo', file_get_contents("{$this->root}/public/storage/logo.png"));

        $this->assertCount(1, File::files($this->dir.'/backups'));
        $this->assertDatabaseHas('activity_logs', ['event' => 'update.completed', 'description' => 'Updated from '.Version::current().' to '.self::NEXT]);
        $this->assertFalse(app()->isDownForMaintenance());
        $this->assertDirectoryDoesNotExist(storage_path('app/private/updates/'.self::NEXT));
    }

    public function test_a_download_that_fails_its_checksum_changes_nothing(): void
    {
        $this->fakeGitHub(checksum: str_repeat('0', 64));

        try {
            $this->updater()->update($this->updater()->latest(fresh: true));
            $this->fail('No exception');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('checksum', $e->getMessage());
        }

        $this->assertFileExists("{$this->root}/app/Old.php");
        $this->assertDatabaseHas('activity_logs', ['event' => 'update.failed']);
        $this->assertFalse(app()->isDownForMaintenance());
    }

    public function test_a_release_without_a_checksum_is_not_installed(): void
    {
        $this->fakeGitHub(withChecksum: false);

        try {
            $this->updater()->update($this->updater()->latest(fresh: true));
            $this->fail('No exception');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('checksum', $e->getMessage());
        }

        $this->assertFileExists("{$this->root}/app/Old.php");
        $this->assertFalse(app()->isDownForMaintenance());
    }

    /** @return array{0: string, 1: string} a secret key and its public key (base64), as php bin/release-key keygen makes them */
    private function keyPair(): array
    {
        $pair = sodium_crypto_sign_keypair();

        return [sodium_crypto_sign_secretkey($pair), base64_encode(sodium_crypto_sign_publickey($pair))];
    }

    /** Http::fake() adds to the earlier fakes; each case needs its own. */
    private function freshHttp(): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
    }

    public function test_with_a_trusted_key_only_a_release_signed_by_it_is_installed(): void
    {
        [$secret, $public] = $this->keyPair();
        [$otherSecret] = $this->keyPair();
        config(['gaiacp.updates.keys' => ['not a key', $public]]);

        $refused = [
            'not signed' => [[], 'not signed'],
            'signed by another key' => [['signedBy' => $otherSecret], 'trusted key'],
            // An older release's checksum, signed, served as this one's
            'signed for another zip' => [['signedBy' => $secret, 'checksumName' => 'gaiacp-0.0.1.zip'], 'gaiacp-0.0.1.zip'],
        ];

        foreach ($refused as $case => [$fake, $message]) {
            $this->freshHttp();
            $this->fakeGitHub(...$fake);

            try {
                $this->updater()->update($this->updater()->latest(fresh: true));
                $this->fail("No exception: {$case}");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString($message, $e->getMessage(), $case);
            }

            $this->assertFileExists("{$this->root}/app/Old.php");
        }

        $this->freshHttp();
        $this->fakeGitHub(signedBy: $secret);
        $this->updater()->update($this->updater()->latest(fresh: true));

        $this->assertFileExists("{$this->root}/app/New.php");
        $this->assertSame([base64_decode($public)], Updater::keys());
    }

    public function test_bin_release_key_signs_what_the_updater_checks(): void
    {
        [$secret, $public] = $this->keyPair();
        $file = "{$this->dir}/gaiacp-".self::NEXT.'.zip.sha256';
        file_put_contents($file, hash('sha256', 'zip').'  gaiacp-'.self::NEXT.".zip\n");

        $sign = Process::env(['RELEASE_SIGNING_KEY' => base64_encode($secret)])->run([PHP_BINARY, base_path('bin/release-key'), 'sign', $file]);
        $this->assertTrue($sign->successful(), $sign->errorOutput());
        $this->assertTrue(sodium_crypto_sign_verify_detached(base64_decode(trim(file_get_contents("{$file}.sig"))), file_get_contents($file), base64_decode($public)));

        $this->assertTrue(Process::run([PHP_BINARY, base_path('bin/release-key'), 'verify', $file, $public])->successful());
        file_put_contents($file, 'changed', FILE_APPEND);
        $this->assertFalse(Process::run([PHP_BINARY, base_path('bin/release-key'), 'verify', $file, $public])->successful());
    }

    public function test_a_failed_migration_puts_the_old_code_back(): void
    {
        $this->fakeGitHub();
        $this->app->bind(Updater::class, fn ($app) => new class($app->make(GitHubReleases::class)) extends Updater
        {
            protected function migrate(): void
            {
                throw new RuntimeException('table exists');
            }
        });

        try {
            $this->updater()->update($this->updater()->latest(fresh: true));
            $this->fail('No exception');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('table exists', $e->getMessage());
        }

        $this->assertFileExists("{$this->root}/app/Old.php");
        $this->assertFileDoesNotExist("{$this->root}/app/New.php");
        $this->assertFileDoesNotExist("{$this->root}/README.md"); // new in the release: gone again
        $this->assertSame('<?php // old', file_get_contents("{$this->root}/vendor/autoload.php"));
        $this->assertSame('the data', file_get_contents("{$this->root}/database/database.sqlite"));
        $this->assertFileExists("{$this->root}/database/migrations/old.php");
        $this->assertFileExists("{$this->root}/plugins/Mine/Plugin.php");
        $this->assertFileExists("{$this->root}/plugins/Cpanel/Old.php");
        $this->assertTrue(is_link("{$this->root}/public/storage"));
        $this->assertDatabaseHas('activity_logs', ['event' => 'update.failed']);
    }

    public function test_a_git_checkout_and_an_older_release_are_refused(): void
    {
        $this->fakeGitHub();
        $release = $this->updater()->latest(fresh: true);

        mkdir("{$this->root}/.git");
        $this->expectExceptionMessage('Git checkout');
        $this->updater()->update($release);
    }

    public function test_a_release_that_is_not_newer_is_refused(): void
    {
        $this->fakeGitHub(version: Version::current());

        $this->expectExceptionMessage('is not newer');
        $this->updater()->update($this->updater()->latest(fresh: true));
    }

    public function test_the_page_shows_the_release_and_the_update_button(): void
    {
        $this->fakeGitHub();
        $this->actingAs(User::factory()->create());

        Livewire::test(Updates::class)
            ->assertSee('GaiaCP '.self::NEXT.' is available')
            ->assertSee('Updates</strong> from the panel', false)
            ->assertDontSee('<script>', false)
            ->assertActionVisible('update');

        mkdir("{$this->root}/.git");
        Livewire::test(Updates::class)
            ->assertSee('This copy runs from a Git checkout')
            ->assertActionHidden('update');
    }

    public function test_the_page_says_when_it_cannot_reach_the_releases(): void
    {
        Http::fake(['api.github.com/*' => Http::response(['message' => 'Not Found'], 404)]);
        $this->actingAs(User::factory()->create());

        Livewire::test(Updates::class)
            ->assertSee('Could not check for updates')
            ->assertSee('GAIACP_UPDATE_TOKEN')
            ->assertActionHidden('update');
    }

    public function test_the_command_checks_and_updates(): void
    {
        $this->fakeGitHub();

        $this->artisan('gaiacp:update', ['--check' => true])
            ->expectsOutputToContain('GaiaCP '.self::NEXT.' is available')
            ->assertSuccessful();
        $this->assertFileExists("{$this->root}/app/Old.php");

        $this->artisan('gaiacp:update', ['--no-interaction' => true])
            ->expectsOutputToContain('Updated to GaiaCP '.self::NEXT)
            ->assertSuccessful();
        $this->assertFileExists("{$this->root}/app/New.php");
    }
}
