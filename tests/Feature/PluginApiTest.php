<?php

namespace Tests\Feature;

use App\Plugins\PluginApi;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/** docs/plugin-api.md: what a plugin may use of GaiaCP, and GaiaCP's own plugins staying inside it. */
class PluginApiTest extends TestCase
{
    public function test_the_bundled_plugins_use_only_the_listed_classes(): void
    {
        $allowed = array_flip([...PluginApi::STABLE, ...PluginApi::INTERNAL]);
        $outside = [];

        foreach (File::allFiles(base_path('plugins')) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            preg_match_all('/\\\\?\b(App\\\\[A-Z][A-Za-z0-9_]*(?:\\\\[A-Z][A-Za-z0-9_]*)+)/', $file->getContents(), $matches);

            foreach (array_unique($matches[1]) as $class) {
                if (! isset($allowed[$class])) {
                    $outside[] = $file->getRelativePathname().': '.$class;
                }
            }
        }

        $this->assertSame([], $outside, 'Add the class to PluginApi::STABLE (and docs/plugin-api.md) or INTERNAL');
    }

    public function test_every_listed_class_exists_once(): void
    {
        $all = [...PluginApi::STABLE, ...PluginApi::INTERNAL];

        $this->assertSame(array_unique($all), $all);

        foreach ($all as $class) {
            $this->assertTrue(class_exists($class) || interface_exists($class) || trait_exists($class) || enum_exists($class), "{$class} does not exist");
        }
    }

    public function test_the_page_names_every_stable_class(): void
    {
        $page = File::get(base_path('docs/plugin-api.md'));

        foreach (PluginApi::STABLE as $class) {
            $this->assertStringContainsString("`{$class}", $page);
        }
    }
}
