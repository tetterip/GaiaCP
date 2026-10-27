<?php

namespace Tests\Feature;

use App\Actions\ServiceLifecycle;
use App\Enums\ServiceStatus;
use App\Filament\Pages\Plugins;
use App\Filament\Resources\Registrars\Pages\CreateRegistrar;
use App\Filament\Resources\Servers\Pages\CreateServer;
use App\Filament\Resources\Servers\Pages\EditServer;
use App\Models\ActivityLog;
use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use App\Plugins\PluginManager;
use App\Plugins\PluginState;
use App\Provisioning\HostingProvisioner;
use App\Provisioning\ProvisionerFactory;
use App\Provisioning\ProvisioningException;
use App\Provisioning\RegistrarFactory;
use App\Support\Version;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Plugins\Aade\AadeRegistry;
use Plugins\Aade\AadeSettings;
use Plugins\WhmcsImport\WhmcsImportPage;
use Tests\FakesWhm;
use Tests\Fixtures\ExamplePanel\ExamplePanelPlugin;
use Tests\TestCase;

class PluginsTest extends TestCase
{
    use FakesWhm;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    private function plugins(): PluginManager
    {
        return app(PluginManager::class);
    }

    public function test_the_bundled_plugins_start_enabled_and_are_listed(): void
    {
        foreach (['cpanel', 'plesk', 'hestia', 'directadmin', 'easygr', 'aade', 'whmcs-import'] as $id) {
            $this->assertSame(PluginState::Enabled, $this->plugins()->state($id));
        }

        $this->get('/admin/settings')->assertOk()->assertSee(Plugins::getUrl());
        $this->get(Plugins::getUrl())->assertOk()->assertSee(['cPanel / WHM', 'Plesk', 'Easy.gr', 'ΑΑΔΕ', 'WHMCS import']);
    }

    public function test_easy_gr_and_aade_start_off_in_a_new_install_but_keep_an_existing_switch(): void
    {
        // An install that already has its rows (every install before this change) keeps them as they are
        DB::table('plugins')->where('id', 'aade')->update(['enabled' => true]);
        DB::table('plugins')->whereIn('id', ['easygr', 'cpanel'])->delete();
        config(['gaiacp.plugins.start_enabled' => []]);
        app()->forgetInstance(PluginManager::class);

        $this->plugins()->recordVersions();

        $this->assertSame(PluginState::Disabled, $this->plugins()->state('easygr'));
        $this->assertSame(PluginState::Enabled, $this->plugins()->state('cpanel'));
        $this->assertSame(PluginState::Enabled, $this->plugins()->state('aade'));

        // GAIACP_PLUGINS_START_ENABLED turns one on in a new install
        DB::table('plugins')->where('id', 'easygr')->delete();
        config(['gaiacp.plugins.start_enabled' => ['easygr']]);
        app()->forgetInstance(PluginManager::class);
        $this->plugins()->recordVersions();
        $this->assertSame(PluginState::Enabled, $this->plugins()->state('easygr'));
    }

    public function test_disable_and_enable_from_the_page_are_logged(): void
    {
        Livewire::test(Plugins::class)
            ->assertTableActionHidden('enable', 'plesk')
            ->callTableAction('disable', 'plesk')
            ->assertNotified('Plesk disabled')
            ->assertTableActionVisible('enable', 'plesk')
            ->assertTableActionHidden('disable', 'plesk');

        $this->assertFalse($this->plugins()->enabled('plesk'));
        $this->assertTrue($this->plugins()->enabled('cpanel'));

        Livewire::test(Plugins::class)->callTableAction('enable', 'plesk')->assertNotified('Plesk enabled');

        $this->assertTrue(app(PluginManager::class)->enabled('plesk'));
        $this->assertSame(['plugin.disabled', 'plugin.enabled'], ActivityLog::query()->orderBy('id')->pluck('event')->all());
    }

    public function test_a_disabled_server_plugin_stops_provisioning_and_keeps_the_service(): void
    {
        $this->fakeWhm();
        $service = Service::factory()->create();
        $this->whmAccounts[$service->username] = false;
        $this->plugins()->disable($this->plugins()->find('cpanel'));

        try {
            app(ServiceLifecycle::class)->suspend($service, 'x');
            $this->fail('No exception');
        } catch (ProvisioningException $e) {
            $this->assertStringContainsString('cPanel / WHM plugin is disabled', $e->getMessage());
        }

        $this->assertSame(ServiceStatus::Active, $service->fresh()->status);
        $this->assertSame([], $this->whmCalls);
        $this->assertTrue(ActivityLog::query()->where('event', 'provisioning.failed')->exists());
    }

    public function test_new_servers_offer_only_enabled_types_but_an_existing_one_keeps_its_own(): void
    {
        $this->plugins()->disable($this->plugins()->find('plesk'));
        $server = Server::factory()->plesk()->create();

        Livewire::test(CreateServer::class)
            ->assertFormFieldExists('type', fn ($field) => array_keys($field->getOptions()) === ['cpanel', 'hestia', 'directadmin']);
        Livewire::test(EditServer::class, ['record' => $server->getRouteKey()])
            ->assertFormFieldExists('type', fn ($field) => array_keys($field->getOptions()) === ['cpanel', 'hestia', 'directadmin', 'plesk']);
    }

    public function test_a_disabled_registrar_plugin_stops_registrar_calls_and_new_accounts(): void
    {
        $this->plugins()->disable($this->plugins()->find('easygr'));

        $this->expectException(ProvisioningException::class);
        $this->expectExceptionMessage('Easy.gr plugin is disabled');

        try {
            Livewire::test(CreateRegistrar::class)
                ->assertFormFieldExists('module', fn ($field) => $field->getOptions() === []);
        } finally {
            app(RegistrarFactory::class)->make('easygr', ['username' => 'u', 'password' => 'p']);
        }
    }

    public function test_a_disabled_aade_plugin_hides_its_settings_and_the_lookup(): void
    {
        config(['gaiacp.aade' => ['enabled' => true, 'username' => 'CODE', 'password' => 'secret', 'afm_called_by' => null]]);
        $this->assertTrue(AadeRegistry::enabled());

        $this->plugins()->disable($this->plugins()->find('aade'));

        $this->assertFalse(AadeRegistry::enabled());
        $this->get(AadeSettings::getUrl())->assertForbidden();
        $this->get('/admin/settings')->assertDontSee(AadeSettings::getUrl());
        Livewire::test(Plugins::class)->assertTableActionHidden('settings', 'aade');
        $this->assertSame('CODE', config('gaiacp.aade.username')); // the codes stay for when it is enabled again
    }

    public function test_a_disabled_whmcs_import_plugin_hides_the_page_and_refuses_the_command(): void
    {
        $this->plugins()->disable($this->plugins()->find('whmcs-import'));

        $this->get(WhmcsImportPage::getUrl())->assertForbidden();
        $this->artisan('gaiacp:import-whmcs', ['--dry-run' => true])
            ->expectsOutputToContain('WHMCS import plugin is disabled')
            ->assertFailed();
    }

    public function test_a_plugin_brings_its_own_server_type_config_and_translations(): void
    {
        $plugin = new ExamplePanelPlugin;
        $this->plugins()->register($plugin);
        $this->assertFalse($this->plugins()->enabled($plugin), 'a plugin that is not bundled starts off');
        $this->plugins()->enable($plugin);
        $this->plugins()->registerConfig($this->app);
        $this->plugins()->registerTranslations($this->app->make('translator'));

        $this->assertSame('eu', config('gaiacp.example_panel.region'));
        $this->assertSame('Λογαριασμοί σε servers Example Panel.', __('Hosting accounts on Example Panel servers.', locale: 'el'));

        Livewire::test(CreateServer::class)
            ->assertFormFieldExists('type', fn ($field) => $field->getOptions()['example'] === 'Example Panel')
            ->fillForm(['type' => 'example'])
            ->assertSchemaStateSet(['port' => 4443])
            ->assertFormFieldExists('username', fn ($field) => ! $field->isRequired());

        $server = Server::factory()->create(['type' => 'example']);
        $this->assertInstanceOf(HostingProvisioner::class, app(ProvisionerFactory::class)->forServer($server));

        $this->plugins()->disable($plugin);
        $this->expectException(ProvisioningException::class);
        app(ProvisionerFactory::class)->forServer($server);
    }

    public function test_bundled_plugins_cannot_be_uninstalled(): void
    {
        $this->plugins()->disable($this->plugins()->find('plesk'));

        foreach (['cpanel', 'plesk', 'whmcs-import'] as $id) {
            Livewire::test(Plugins::class)->assertTableActionHidden('uninstall', $id);
        }
    }

    public function test_every_bundled_plugin_has_a_version_a_requirement_and_a_changelog_entry(): void
    {
        foreach (PluginManager::BUILT_IN as $class) {
            $plugin = new $class;

            $this->assertFileExists($plugin->path().'/plugin.json', $plugin->name());
            $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+(-[0-9A-Za-z.-]+)?$/', $plugin->version(), $plugin->name());
            $this->assertNotNull($plugin->requires(), $plugin->name());
            $this->assertTrue(version_compare(Version::current(), $plugin->requires(), '>='), "{$plugin->name()} needs a newer GaiaCP than this one");
            $this->assertStringContainsString('## ['.$plugin->version().'] - ', (string) $plugin->changelog(), "{$plugin->name()}: CHANGELOG.md has no entry for its version");
        }
    }

    public function test_migrate_records_each_plugins_version_and_logs_an_update_once(): void
    {
        // RefreshDatabase's migrate already noted the bundled ones, without logging.
        $this->assertSame($this->plugins()->find('cpanel')->version(), DB::table('plugins')->where('id', 'cpanel')->value('version'));
        $this->assertSame(0, ActivityLog::query()->count());

        $this->plugins()->register(new ExamplePanelPlugin);
        $this->artisan('migrate')->assertSuccessful();
        $this->assertSame('1.0.0', $this->plugins()->installed($this->plugins()->find('example-panel'))->version);

        Carbon::setTestNow('2026-11-02 10:00');
        $this->plugins()->register($newer = new class extends ExamplePanelPlugin
        {
            public function version(): string
            {
                return '1.1.0';
            }
        });
        $this->artisan('migrate')->assertSuccessful();
        $this->artisan('migrate')->assertSuccessful();

        $installed = $this->plugins()->installed($newer);
        $this->assertSame(['1.1.0', '1.0.0', '2026-11-02'], [$installed->version, $installed->previous, $installed->changedAt->toDateString()]);
        $this->assertSame(['Plugin Example Panel updated from 1.0.0 to 1.1.0'], ActivityLog::query()->where('event', 'plugin.updated')->pluck('description')->all());

        // A switch keeps the recorded version.
        $this->plugins()->disable($newer);
        $this->assertSame('1.1.0', $this->plugins()->installed($newer)->version);

        Livewire::test(Plugins::class)
            ->assertSee(['v1.1.0', 'Updated from 1.0.0 on 02/11/2026', 'v'.$this->plugins()->find('cpanel')->version(), 'Installed on'])
            ->assertTableActionVisible('changelog', 'cpanel')
            ->mountTableAction('changelog', 'cpanel')
            ->assertMountedActionModalSee(['cPanel / WHM changelog', '1.0.0', 'WHM API 1']);
    }

    public function test_a_plugin_needing_a_newer_gaiacp_stays_off(): void
    {
        $this->plugins()->register($plugin = new class extends ExamplePanelPlugin
        {
            public function requires(): ?string
            {
                return '99.0.0';
            }
        });

        $this->assertSame(PluginState::Incompatible, $this->plugins()->state($plugin));
        $this->assertArrayNotHasKey('example', $this->plugins()->serverModules()->all());
        $this->plugins()->enable($plugin);
        $this->assertFalse($this->plugins()->enabled($plugin));

        Livewire::test(Plugins::class)
            ->assertSee(['Needs a newer GaiaCP', 'Needs GaiaCP 99.0.0 or later'])
            ->assertTableActionHidden('enable', 'example-panel')
            ->assertTableActionHidden('disable', 'example-panel');

        $this->expectExceptionMessage('The Example Panel plugin needs GaiaCP 99.0.0 or later.');
        $this->plugins()->enabledServerModule('example');
    }
}
