<?php

namespace Tests\Feature\Provisioning;

use App\Actions\ServiceLifecycle;
use App\Enums\ServiceStatus;
use App\Models\Client;
use App\Models\ModuleLog;
use App\Models\Server;
use App\Models\Service;
use App\Plugins\PluginManager;
use App\Provisioning\AccountState;
use App\Provisioning\PackageOptions;
use App\Provisioning\ProvisionerFactory;
use App\Provisioning\ProvisioningException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Plugins\Virtualmin\VirtualminPlugin;
use Plugins\Virtualmin\VirtualminProvisioner;
use Tests\FakesVirtualmin;
use Tests\TestCase;

/** Virtualmin: one top-level virtual server per hosting service, on a plan, over remote.cgi as root. */
class VirtualminLifecycleTest extends TestCase
{
    use FakesVirtualmin, RefreshDatabase;

    private ServiceLifecycle $lifecycle;

    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();

        $plugins = app(PluginManager::class);
        $plugins->enable($plugins->find('virtualmin'));

        $this->fakeVirtualmin();
        $this->lifecycle = app(ServiceLifecycle::class);
        $this->server = Server::factory()->create(['type' => 'virtualmin', 'hostname' => 'vm.test', 'port' => 10000, 'username' => 'root', 'api_token' => $this->virtualminPassword]);
    }

    private function pending(array $attributes = []): Service
    {
        return Service::factory()->for($this->server)->create([
            'client_id' => Client::factory()->create(['first_name' => 'Maria', 'last_name' => 'Papa', 'email' => 'maria@example.com'])->id,
            'status' => ServiceStatus::Pending,
            'domain' => 'bakery.gr',
            'username' => null,
            'package' => 'Starter',
            ...$attributes,
        ]);
    }

    public function test_off_in_a_new_install(): void
    {
        $this->assertFalse((new VirtualminPlugin)->enabledByDefault());
    }

    public function test_connection_and_plans(): void
    {
        $vm = app(ProvisionerFactory::class)->forServer($this->server);

        $this->assertSame('Virtualmin, 3 plans', $vm->testConnection());
        $this->assertSame(['Default Plan', 'Starter', 'Business'], $vm->packages());
        $this->assertSame(['Default Plan', 'Starter', 'Business'], PackageOptions::forServer($this->server->id));

        Http::assertSent(fn (Request $request) => $request->url() === 'https://vm.test:10000/virtual-server/remote.cgi'
            && $request->method() === 'POST'
            && $request['program'] === 'list-plans' && $request['json'] === '1' && $request['multiline'] === '');
    }

    public function test_a_wrong_login_says_so(): void
    {
        $this->server->update(['api_token' => 'wrong']);

        $this->expectExceptionMessage('login refused (HTTP 401)');

        app(ProvisionerFactory::class)->forServer($this->server)->testConnection();
    }

    public function test_create_makes_a_virtual_server_on_the_plan(): void
    {
        $service = $this->pending(['domain' => 'φούρνος.gr']);

        $this->lifecycle->create($service);

        $service->refresh();
        $this->assertSame(ServiceStatus::Active, $service->status);
        $this->assertSame(['list-domains', 'create-domain'], $this->virtualminPrograms());

        $created = $this->virtualminDomains['xn--yxadbght4c.gr'] ?? null;
        $this->assertNotNull($created, 'the domain goes to Virtualmin in punycode');
        $this->assertSame([$service->username, 'Starter', $service->password, 'maria@example.com', 'Maria Papa'], [$created['user'], $created['plan'], $created['pass'], $created['email'], $created['desc']]);
        $this->assertSame('', $this->virtualminCalls[1][1]['features-from-plan']);
        Http::assertSent(fn (Request $request) => ! str_contains($request->url(), (string) $service->password));
    }

    public function test_create_retry_finds_its_own_server_and_refuses_another_domain(): void
    {
        $this->virtualminDomains['bakery.gr'] = ['user' => 'bakery', 'plan' => 'Starter', 'pass' => 'x'];
        $service = $this->pending(['username' => 'bakery']);

        $this->lifecycle->create($service);

        $this->assertNotContains('create-domain', $this->virtualminPrograms());
        $this->assertSame(ServiceStatus::Active, $service->fresh()->status);

        $this->virtualminDomains = ['other.gr' => ['user' => 'shop', 'plan' => 'Starter', 'pass' => 'x']];
        $other = $this->pending(['username' => 'shop']);
        $this->expectExceptionMessage('already has a different account for this service (other.gr)');
        $this->lifecycle->create($other);
    }

    public function test_suspend_unsuspend_change_plan_password_terminate(): void
    {
        $service = $this->pending();
        $this->lifecycle->create($service);
        $vm = app(ProvisionerFactory::class)->forServer($this->server);

        $this->lifecycle->suspend($service->fresh(), Service::SUSPENDED_OVERDUE);
        $this->assertArrayHasKey('disabled', $this->virtualminDomains['bakery.gr']);
        $this->assertSame(AccountState::Suspended, $vm->accountState($service->fresh()));
        $this->assertSame(ServiceStatus::Suspended, $service->fresh()->status);

        $this->lifecycle->unsuspend($service->fresh());
        $this->assertArrayNotHasKey('disabled', $this->virtualminDomains['bakery.gr']);

        $this->lifecycle->changePackage($service->fresh(), 'Business');
        $this->assertSame('Business', $this->virtualminDomains['bakery.gr']['plan']);

        $this->lifecycle->changePassword($service->fresh(), 'NewSecret123');
        $this->assertSame('NewSecret123', $this->virtualminDomains['bakery.gr']['pass']);

        $this->lifecycle->terminate($service->fresh());
        $this->assertSame([], $this->virtualminDomains);
        $this->assertSame(ServiceStatus::Terminated, $service->fresh()->status);
    }

    public function test_an_unknown_plan_is_reported(): void
    {
        $service = $this->pending();
        $this->lifecycle->create($service);

        $this->expectExceptionMessage('Virtualmin modify-domain: Plan Gold does not exist');

        $this->lifecycle->changePackage($service->fresh(), 'Gold');
    }

    public function test_accounts_and_usage(): void
    {
        $this->virtualminDomains['bakery.gr'] = ['user' => 'bakery', 'plan' => 'Starter', 'pass' => 'x', 'fields' => [
            'server_byte_quota_used' => ['157286400'], 'server_quota' => ['1 GB'], 'bandwidth_byte_usage' => ['2147483648'],
            'bandwidth_limit' => ['Unlimited'], 'databases_count' => ['2'], 'maximum_databases' => ['5'], 'databases_size' => ['12 MB'],
        ]];
        $this->virtualminDomains['shop.gr'] = ['user' => 'shop', 'plan' => 'Business', 'pass' => 'x', 'disabled' => 'For non-payment'];
        $vm = app(ProvisionerFactory::class)->forServer($this->server);

        $accounts = collect($vm->accounts())->keyBy('username');
        $this->assertSame(['bakery.gr', 'Starter', false], [$accounts['bakery']->domain, $accounts['bakery']->package, $accounts['bakery']->suspended]);
        $this->assertTrue($accounts['shop']->suspended);

        $usage = $this->lifecycle->usage($this->pending(['username' => 'bakery', 'status' => ServiceStatus::Active]));
        $mb = 1024 ** 2;
        $this->assertSame([150 * $mb, 1024 * $mb, 2048 * $mb, null], [$usage->diskUsed, $usage->diskLimit, $usage->bandwidthUsed, $usage->bandwidthLimit]);
        $this->assertSame(['databases' => [2, 5], 'database_disk' => [12 * $mb, null]], $usage->counts);
    }

    public function test_sizes(): void
    {
        $this->assertSame([1234, 1536, 1024 ** 3, null, null], [VirtualminProvisioner::bytes('1234'), VirtualminProvisioner::bytes('1.5 kB'),
            VirtualminProvisioner::bytes('1 GiB'), VirtualminProvisioner::bytes('Unlimited'), VirtualminProvisioner::bytes(null)]);
    }

    public function test_panel_login_is_the_login_page(): void
    {
        $service = $this->pending(['username' => 'bakery', 'status' => ServiceStatus::Active]);

        $this->assertSame('https://vm.test:10000/', $this->lifecycle->loginUrl($service, '203.0.113.9'));
        $this->assertSame([], $this->virtualminCalls);
    }

    public function test_a_failure_leaves_the_service_unchanged(): void
    {
        $this->virtualminFailures['create-domain'] = 'Disk quotas are not enabled';
        $service = $this->pending();

        try {
            $this->lifecycle->create($service);
            $this->fail('expected an exception');
        } catch (ProvisioningException $e) {
            $this->assertSame('Virtualmin create-domain: Disk quotas are not enabled', $e->getMessage());
        }

        $this->assertSame(ServiceStatus::Pending, $service->fresh()->status);
    }

    public function test_the_module_log_masks_the_passwords(): void
    {
        config(['gaiacp.debug.module_log' => true]);
        $service = $this->pending();

        $this->lifecycle->create($service);

        $logs = ModuleLog::where('module', 'virtualmin')->orderBy('id')->get();
        $this->assertSame(['list-domains', 'create-domain'], $logs->pluck('action')->all());
        $all = $logs->map(fn (ModuleLog $l) => $l->request.$l->response)->implode('');
        $this->assertStringContainsString('program=create-domain', $all);
        foreach ([$service->fresh()->password, $this->virtualminPassword, base64_encode('root:'.$this->virtualminPassword)] as $secret) {
            $this->assertStringNotContainsString($secret, $all);
        }
    }
}
