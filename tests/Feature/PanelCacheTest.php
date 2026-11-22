<?php

namespace Tests\Feature;

use App\Plugins\PluginManager;
use App\Support\Health\PanelCache;
use Tests\TestCase;

/** A Filament component cache that names a plugin page which is gone is dropped instead of breaking every admin page. */
class PanelCacheTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/gaiacp-panel-cache-'.uniqid();
        mkdir($this->dir.'/panels', 0777, true);
        config(['filament.cache_path' => $this->dir]);
    }

    protected function tearDown(): void
    {
        @unlink($this->dir.'/panels/admin.php');
        @rmdir($this->dir.'/panels');
        @rmdir($this->dir);

        parent::tearDown();
    }

    /** @param  list<string>  $pages */
    private function writeCache(array $pages): string
    {
        $path = $this->dir.'/panels/admin.php';
        file_put_contents($path, '<?php return '.var_export(['pages' => array_combine($pages, $pages)], true).';');

        return $path;
    }

    public function test_a_cache_naming_a_removed_plugins_page_is_dropped(): void
    {
        $pages = app(PluginManager::class)->pages();
        $path = $this->writeCache([...$pages, 'Plugins\\Discord\\DiscordSettings', 'App\\Filament\\Pages\\Dashboard']);

        $this->assertTrue(PanelCache::forgetIfStale('admin', $pages));
        $this->assertFileDoesNotExist($path);
    }

    public function test_a_cache_missing_a_new_plugins_page_is_dropped(): void
    {
        $pages = app(PluginManager::class)->pages();
        $path = $this->writeCache(array_slice($pages, 1));

        $this->assertTrue(PanelCache::forgetIfStale('admin', $pages));
        $this->assertFileDoesNotExist($path);
    }

    public function test_a_cache_with_the_same_plugin_pages_is_kept(): void
    {
        $pages = app(PluginManager::class)->pages();
        $path = $this->writeCache(['App\\Filament\\Pages\\Dashboard', ...array_reverse($pages)]);

        $this->assertFalse(PanelCache::forgetIfStale('admin', $pages));
        $this->assertFileExists($path);
    }

    public function test_no_cache_is_left_alone(): void
    {
        $this->assertFalse(PanelCache::forgetIfStale('admin', app(PluginManager::class)->pages()));
    }
}
