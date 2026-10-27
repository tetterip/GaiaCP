<?php

namespace Tests\Feature;

use App\Plugins\Catalogue\Catalogue;
use App\Plugins\Catalogue\CatalogueException;
use App\Support\Version;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PluginCatalogueTest extends TestCase
{
    private string $secret;

    private string $zip = 'zip bytes of HelloWorld 1.1.0';

    /** @var array<string, string> path => body the fake server answers with */
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();

        $pair = sodium_crypto_sign_keypair();
        $this->secret = sodium_crypto_sign_secretkey($pair);

        config([
            'gaiacp.plugins.catalogue.enabled' => true,
            'gaiacp.plugins.catalogue.url' => 'https://plugins.example.test',
            'gaiacp.plugins.catalogue.keys' => [base64_encode(sodium_crypto_sign_publickey($pair))],
        ]);

        File::deleteDirectory(storage_path('app/private/plugins/catalogue'));

        Http::fake(fn ($request) => isset($this->files[$path = parse_url($request->url(), PHP_URL_PATH)])
            ? Http::response($this->files[$path])
            : Http::response('', 404));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('app/private/plugins/catalogue'));

        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function catalogue(string $generatedAt = '2026-10-25T12:00:00Z'): array
    {
        return [
            'format' => 1,
            'generated_at' => $generatedAt,
            'plugins' => [[
                'folder' => 'HelloWorld',
                'name' => 'Hello World',
                'description' => ['en' => 'Says hello', 'el' => 'Λέει γεια'],
                'category' => 'Integrations',
                'author' => ['name' => 'GaiaCP', 'url' => 'https://gaiacp.digital'],
                'licence' => 'GPL-3.0-or-later',
                'official' => true,
                'versions' => [
                    ['version' => '1.0.0', 'requires' => '0.1.0', 'url' => 'zips/HelloWorld-1.0.0.zip', 'sha256' => str_repeat('a', 64), 'size' => 10],
                    ['version' => '2.0.0', 'requires' => '99.0.0', 'url' => 'zips/HelloWorld-2.0.0.zip', 'sha256' => str_repeat('b', 64), 'size' => 10],
                    ['version' => '1.1.0', 'requires' => '0.1.0', 'php' => '8.3', 'url' => 'zips/HelloWorld-1.1.0.zip', 'sha256' => hash('sha256', $this->zip), 'size' => strlen($this->zip), 'changelog' => '- Waves too'],
                ],
            ]],
        ];
    }

    private function serve(array $catalogue, ?string $secret = null): void
    {
        $json = json_encode($catalogue);

        $this->files = [
            '/catalogue.json' => $json,
            '/catalogue.json.sig' => base64_encode(sodium_crypto_sign_detached($json, $secret ?? $this->secret)),
            '/zips/HelloWorld-1.1.0.zip' => $this->zip,
        ];
    }

    public function test_reads_a_signed_catalogue_and_keeps_it(): void
    {
        $this->serve($this->catalogue());

        $catalogue = app(Catalogue::class)->fetch();
        $plugin = $catalogue->find('helloworld');

        $this->assertSame('Hello World', $plugin->name);
        $this->assertSame('Plugins\HelloWorld\HelloWorldPlugin', $plugin->class);
        $this->assertSame('Λέει γεια', $plugin->description('el'));
        $this->assertSame('Says hello', $plugin->description('fr'));
        $this->assertSame(['2.0.0', '1.1.0', '1.0.0'], array_map(fn ($version) => $version->version, $plugin->versions));
        $this->assertSame('https://plugins.example.test/zips/HelloWorld-1.1.0.zip', $plugin->best()->url);
        $this->assertSame('1.1.0', $plugin->best()->version, 'the newest one that runs on this GaiaCP');

        Http::assertSent(fn ($request) => $request->hasHeader('User-Agent', 'GaiaCP/'.Version::current()));
        $this->assertSame('1.1.0', app(Catalogue::class)->stored()->find('HelloWorld')->best()->version);
    }

    public function test_refuses_a_catalogue_signed_by_another_key(): void
    {
        $this->serve($this->catalogue(), sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()));

        $this->expectException(CatalogueException::class);
        $this->expectExceptionMessage('signature does not match');

        try {
            app(Catalogue::class)->fetch();
        } finally {
            $this->assertNull(app(Catalogue::class)->stored());
        }
    }

    public function test_refuses_a_changed_catalogue(): void
    {
        $json = json_encode($this->catalogue());
        $signature = base64_encode(sodium_crypto_sign_detached($json, $this->secret));

        $this->expectException(CatalogueException::class);

        Catalogue::verify(str_replace('Hello World', 'Hello Wrold', $json), $signature);
    }

    public function test_refuses_an_older_catalogue_than_the_one_read(): void
    {
        $this->serve($this->catalogue('2026-10-25T12:00:00Z'));
        app(Catalogue::class)->fetch();

        $this->serve($this->catalogue('2026-10-20T12:00:00Z'));

        $this->expectException(CatalogueException::class);
        $this->expectExceptionMessage('older than the one already read');

        app(Catalogue::class)->fetch();
    }

    public function test_the_official_key_is_trusted_out_of_the_box(): void
    {
        $keys = (require config_path('gaiacp.php'))['plugins']['catalogue']['keys'];

        config(['gaiacp.plugins.catalogue.keys' => $keys]);

        $this->assertCount(1, Catalogue::keys());
        $this->assertTrue(Catalogue::enabled());
    }

    public function test_is_off_without_a_trusted_key(): void
    {
        config(['gaiacp.plugins.catalogue.keys' => ['not a key']]);

        $this->assertFalse(Catalogue::enabled());
        $this->expectException(CatalogueException::class);

        app(Catalogue::class)->fetch();
    }

    public function test_refuses_a_zip_outside_https(): void
    {
        $catalogue = $this->catalogue();
        $catalogue['plugins'][0]['versions'][0]['url'] = 'http://elsewhere.test/HelloWorld.zip';
        $this->serve($catalogue);

        $this->expectException(CatalogueException::class);
        $this->expectExceptionMessage('not served over https');

        app(Catalogue::class)->fetch();
    }

    public function test_downloads_a_zip_and_checks_it(): void
    {
        $this->serve($this->catalogue());
        $version = app(Catalogue::class)->fetch()->find('HelloWorld')->best();

        $path = app(Catalogue::class)->download($version);

        $this->assertSame($this->zip, file_get_contents($path));
    }

    public function test_refuses_a_zip_that_is_not_the_one_signed(): void
    {
        $this->serve($this->catalogue());
        $version = app(Catalogue::class)->fetch()->find('HelloWorld')->best();

        $this->files['/zips/HelloWorld-1.1.0.zip'] = str_replace('1.1.0', '6.6.6', $this->zip);

        $this->expectException(CatalogueException::class);
        $this->expectExceptionMessage('checksum differs');

        try {
            app(Catalogue::class)->download($version);
        } finally {
            $this->assertSame([], File::files(storage_path('app/private/plugins/catalogue/downloads')));
        }
    }
}
