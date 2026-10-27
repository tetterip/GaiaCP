<?php

namespace Tests\Feature\Provisioning;

use App\Actions\ServiceLifecycle;
use App\Enums\ServiceStatus;
use App\Models\Client;
use App\Models\ModuleLog;
use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use App\Plugins\PluginManager;
use App\Provisioning\AccountState;
use App\Provisioning\PackageOptions;
use App\Provisioning\ProvisionerFactory;
use App\Provisioning\ProvisioningException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Plugins\CyberPanel\CyberPanelPlugin;
use Plugins\CyberPanel\CyberPanelSettings;
use Tests\FakesCyberPanel;
use Tests\TestCase;

/** CyberPanel: one website per hosting service, owned by a user of the service's username, made by the admin login. */
class CyberPanelLifecycleTest extends TestCase
{
    use FakesCyberPanel, RefreshDatabase;

    private ServiceLifecycle $lifecycle;

    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();

        $plugins = app(PluginManager::class);
        $plugins->enable($plugins->find('cyberpanel'));

        $this->fakeCyberPanel();
        $this->lifecycle = app(ServiceLifecycle::class);
        $this->server = Server::factory()->create(['type' => 'cyberpanel', 'hostname' => 'cp.test', 'port' => 8090, 'username' => 'admin', 'api_token' => $this->cpPassword]);
    }

    private function pending(array $attributes = []): Service
    {
        return Service::factory()->for($this->server)->create([
            'client_id' => Client::factory()->create(['email' => 'maria@example.com'])->id,
            'status' => ServiceStatus::Pending,
            'domain' => 'bakery-shop.gr',
            'username' => null,
            'package' => 'admin_Default',
            ...$attributes,
        ]);
    }

    public function test_off_in_a_new_install(): void
    {
        $this->assertFalse((new CyberPanelPlugin)->enabledByDefault());
    }

    public function test_connection_and_packages(): void
    {
        $cp = app(ProvisionerFactory::class)->forServer($this->server);

        $this->assertSame('CyberPanel 2.4.3', $cp->testConnection());
        $this->assertSame(['cyberPanelVersion', 'verifyConn', 'verifyLogin'], $this->cpActions());
        $this->assertSame(['admin_Default', 'admin_Pro'], $cp->packages());
        $this->assertSame(['admin_Default', 'admin_Pro'], PackageOptions::forServer($this->server->id));

        Http::assertSent(fn (Request $request) => $request->url() === 'https://cp.test:8090/cloudAPI/'
            && $request->header('Authorization')[0] === $this->cpToken
            && $request['serverUserName'] === 'admin');
    }

    public function test_only_the_admin_login_may_read_the_websites(): void
    {
        $this->server->update(['username' => 'reseller1']);
        $this->cpPassword = 'AdminSecret/1';

        $this->expectExceptionMessage('CyberPanel lets only its admin user read the websites');

        app(ProvisionerFactory::class)->forServer($this->server)->testConnection();
    }

    public function test_a_wrong_password_is_reported(): void
    {
        $this->server->update(['api_token' => 'wrong']);

        $this->expectExceptionMessage('CyberPanel cyberPanelVersion: Could not authorize access to API.');

        app(ProvisionerFactory::class)->forServer($this->server)->testConnection();
    }

    public function test_a_web_page_instead_of_the_api_is_explained(): void
    {
        $this->cpRawResponse = Http::response('<html>login</html>', 200, ['Content-Type' => 'text/html']);

        $this->expectExceptionMessage('without the API\'s reply. Check the port (8090)');

        app(ProvisionerFactory::class)->forServer($this->server)->testConnection();
    }

    public function test_create_makes_the_website_and_its_owner(): void
    {
        config(['gaiacp.cyberpanel.php_version' => 'PHP 8.3']);
        $service = $this->pending(['domain' => 'φούρνος.gr']);

        $this->lifecycle->create($service);

        $service->refresh();
        $this->assertSame(ServiceStatus::Active, $service->status);
        $this->assertSame(['verifyConn', 'fetchWebsites', 'createWebsite'], $this->cpActions());

        $params = $this->cpCalls[2][1];
        $this->assertSame(
            ['admin', $this->cpPassword, 'xn--yxadbght4c.gr', 'maria@example.com', 'admin_Default', $service->username, $service->password, 'PHP 8.3'],
            [$params['adminUser'], $params['adminPass'], $params['domainName'], $params['ownerEmail'], $params['packageName'], $params['websiteOwner'], $params['ownerPassword'], $params['phpSelection']],
        );
        $this->assertSame($service->username, $this->cpWebsites['xn--yxadbght4c.gr']['admin']);
        $this->assertSame('φούρνος.gr', app(ProvisionerFactory::class)->forServer($this->server)->accountDomain($service));
    }

    public function test_create_retry_finds_its_own_website_and_refuses_another_domain(): void
    {
        $service = $this->pending(['username' => 'bakery']);
        $this->cpWebsites['bakery-shop.gr'] = ['admin' => 'bakery', 'package' => 'admin_Default', 'state' => 'Active'];

        $this->lifecycle->create($service);

        $this->assertNotContains('createWebsite', $this->cpActions());
        $this->assertSame(ServiceStatus::Active, $service->fresh()->status);

        $other = $this->pending(['username' => 'taken', 'domain' => 'mine.gr']);
        $this->cpWebsites['theirs.gr'] = ['admin' => 'taken', 'package' => 'admin_Default', 'state' => 'Active'];

        $this->expectExceptionMessage('already has a different account for this service (theirs.gr)');

        $this->lifecycle->create($other);
    }

    public function test_a_domain_under_another_owner_is_cyberpanels_error(): void
    {
        $this->cpWebsites['bakery-shop.gr'] = ['admin' => 'someoneelse', 'package' => 'admin_Default', 'state' => 'Active'];
        $service = $this->pending();

        try {
            $this->lifecycle->create($service);
            $this->fail('expected an exception');
        } catch (ProvisioningException $e) {
            $this->assertSame('CyberPanel createWebsite: This website already exists.', $e->getMessage());
        }

        $this->assertSame(ServiceStatus::Pending, $service->fresh()->status);
    }

    public function test_suspend_unsuspend_change_package_password_terminate(): void
    {
        $service = $this->pending();
        $this->lifecycle->create($service);
        $username = $service->refresh()->username;

        $this->lifecycle->suspend($service, Service::SUSPENDED_OVERDUE);
        $this->assertSame('Suspended', $this->cpWebsites['bakery-shop.gr']['state']);
        $this->assertSame(['bakery-shop.gr', 'Suspend'], [last($this->cpCalls)[1]['websiteName'], last($this->cpCalls)[1]['state']]);
        $this->assertSame(ServiceStatus::Suspended, $service->fresh()->status);

        $this->lifecycle->unsuspend($service->fresh());
        $this->assertSame('Active', $this->cpWebsites['bakery-shop.gr']['state']);

        $this->lifecycle->changePackage($service->fresh(), 'admin_Pro');
        $this->assertSame('admin_Pro', $this->cpWebsites['bakery-shop.gr']['package']);

        $this->lifecycle->changePassword($service->fresh(), 'NewSecret123');
        $this->assertSame('NewSecret123', $this->cpUsers[$username]);

        $this->lifecycle->terminate($service->fresh());
        $this->assertSame([], $this->cpWebsites);
        $this->assertArrayNotHasKey($username, $this->cpUsers);
        $this->assertSame(['deleteWebsite', 'fetchWebsites', 'getUserInfo', 'submitUserDeletion'], array_slice($this->cpActions(), -4));
        $this->assertSame(ServiceStatus::Terminated, $service->fresh()->status);
    }

    public function test_terminate_keeps_an_owner_with_other_websites(): void
    {
        $service = $this->pending(['username' => 'bakery', 'status' => ServiceStatus::Active]);
        $this->cpUsers['bakery'] = 'x';
        $this->cpWebsites['bakery-shop.gr'] = ['admin' => 'bakery', 'package' => 'admin_Default', 'state' => 'Active'];
        $this->cpWebsites['other.gr'] = ['admin' => 'bakery', 'package' => 'admin_Default', 'state' => 'Active'];

        $this->lifecycle->terminate($service);

        $this->assertSame(['other.gr'], array_keys($this->cpWebsites));
        $this->assertArrayHasKey('bakery', $this->cpUsers);
        $this->assertNotContains('submitUserDeletion', $this->cpActions());
    }

    public function test_websites_are_read_page_by_page(): void
    {
        foreach (range(1, 23) as $i) {
            $this->cpWebsites["site{$i}.gr"] = ['admin' => "user{$i}", 'package' => 'admin_Default', 'state' => $i === 23 ? 'Suspended' : 'Active'];
        }

        $accounts = app(ProvisionerFactory::class)->forServer($this->server)->accounts();

        $this->assertCount(23, $accounts);
        $this->assertSame(['user23', 'site23.gr', 'admin_Default', true], [$accounts[22]->username, $accounts[22]->domain, $accounts[22]->package, $accounts[22]->suspended]);
        $this->assertSame([1, 2, 3], array_column(array_column(array_filter($this->cpCalls, fn ($c) => $c[0] === 'fetchWebsites'), 1), 'page'));

        $service = $this->pending(['username' => 'user23', 'domain' => 'site23.gr', 'status' => ServiceStatus::Suspended]);
        $this->assertSame(AccountState::Suspended, app(ProvisionerFactory::class)->forServer($this->server)->accountState($service));
    }

    public function test_usage_comes_from_the_websites_data(): void
    {
        $service = $this->pending(['username' => 'bakery', 'status' => ServiceStatus::Active]);
        $this->cpWebsites['bakery-shop.gr'] = ['admin' => 'bakery', 'package' => 'admin_Default', 'state' => 'Active'];
        $this->cpUsage['bakery-shop.gr'] = ['diskInMB' => 150.5, 'diskInMBTotal' => 1000, 'bwInMB' => 2048, 'bwInMBTotal' => 0, 'dbUsed' => 1, 'dbAllowed' => 5, 'ftpUsed' => 1, 'ftpAllowed' => 1];

        $usage = $this->lifecycle->usage($service);

        $mb = 1024 ** 2;
        $this->assertSame([(int) round(150.5 * $mb), 1000 * $mb, 2048 * $mb, null], [$usage->diskUsed, $usage->diskLimit, $usage->bandwidthUsed, $usage->bandwidthLimit]);
        $this->assertSame(['databases' => [1, 5]], $usage->counts);
    }

    public function test_panel_login_is_the_login_page(): void
    {
        $service = $this->pending(['username' => 'bakery', 'status' => ServiceStatus::Active]);

        $this->assertSame('https://cp.test:8090/', $this->lifecycle->loginUrl($service, '203.0.113.9'));
        $this->assertSame([], $this->cpCalls);
    }

    public function test_the_module_log_masks_the_passwords_and_the_api_token(): void
    {
        config(['gaiacp.debug.module_log' => true]);
        $service = $this->pending();

        $this->lifecycle->create($service);

        $logs = ModuleLog::where('module', 'cyberpanel')->orderBy('id')->get();
        $this->assertSame(['verifyConn', 'fetchWebsites', 'createWebsite'], $logs->pluck('action')->all());
        $all = $logs->map(fn (ModuleLog $l) => $l->url.$l->request.$l->response)->implode('');
        $this->assertStringContainsString('bakery-shop.gr', $all);
        foreach ([$service->fresh()->password, $this->cpPassword, 'AdminSecret\/1', 'cp_TOKEN0123456789'] as $secret) {
            $this->assertStringNotContainsString($secret, $all);
        }
    }

    public function test_settings_page_saves_the_php_version(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(CyberPanelSettings::class)
            ->assertFormSet(['cyberpanel.php_version' => 'PHP 8.2'])
            ->fillForm(['cyberpanel.php_version' => 'PHP 8.4'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('PHP 8.4', config('gaiacp.cyberpanel.php_version'));
    }
}
