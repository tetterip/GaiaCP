<?php

namespace Tests\Feature;

use App\Models\User;
use App\Plugins\PluginManager;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;
use ReflectionFunction;
use Tests\TestCase;

/** Access is never granted by mass assignment, and the plugin autoloader only loads class names made of identifiers. */
class MassAssignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_and_admin_areas_are_not_mass_assignable(): void
    {
        User::factory()->create(); // the first admin, a super admin by itself
        $user = User::factory()->create(['super_admin' => false, 'admin_areas' => ['support']]);

        $user->fill(['super_admin' => true, 'admin_areas' => null, 'name' => 'Changed'])->save();
        $user->refresh();

        $this->assertFalse($user->super_admin);
        $this->assertSame(['support'], $user->admin_areas);
        $this->assertSame('Changed', $user->name);
    }

    public function test_the_plugin_autoloader_ignores_class_names_that_are_not_identifiers(): void
    {
        $outside = dirname(base_path('plugins'), 2).'/gaiacp-autoload-probe.php';
        file_put_contents($outside, '<?php $GLOBALS["gaiacp_autoload_probe"] = true;');

        try {
            // PHP itself refuses such a name in class_exists() or new; the autoloader is asked directly here
            $loaders = array_filter(spl_autoload_functions(), fn ($loader) => $loader instanceof Closure
                && (new ReflectionFunction($loader))->getFileName() === (new ReflectionClass(PluginManager::class))->getFileName());
            $this->assertNotEmpty($loaders);

            foreach ($loaders as $loader) {
                $loader('Plugins\\..\\..\\gaiacp-autoload-probe');
            }

            $this->assertArrayNotHasKey('gaiacp_autoload_probe', $GLOBALS);
        } finally {
            @unlink($outside);
        }
    }
}
