<?php

namespace Tests\Feature\Provisioning;

use App\Actions\ServiceLifecycle;
use App\Enums\ServiceStatus;
use App\Enums\UserRole;
use App\Filament\Actions\ServiceLifecycleActions;
use App\Filament\Client\Actions\HostingSelfService;
use App\Filament\Client\Resources\Services\Pages\ViewService;
use App\Filament\Resources\Services\Pages\EditService;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use App\Plugins\PluginManager;
use App\Provisioning\AccountState;
use App\Provisioning\ProvisionerFactory;
use App\Provisioning\ProvisioningException;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Livewire\Livewire;
use Plugins\Proxmox\ProxmoxAddress;
use Plugins\Proxmox\ProxmoxProvisioner;
use Tests\FakesProxmox;
use Tests\TestCase;

/** Proxmox VE: one KVM machine per hosting service (a VPS), cloned from the template its package names. */
class ProxmoxLifecycleTest extends TestCase
{
    use FakesProxmox, RefreshDatabase;

    private ServiceLifecycle $lifecycle;

    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
        $this->fakeProxmox();
        $plugins = app(PluginManager::class);
        $plugins->enable($plugins->find('proxmox'));

        $this->lifecycle = app(ServiceLifecycle::class);
        $this->server = Server::factory()->create(['type' => 'proxmox', 'hostname' => 'pve.test', 'port' => 8006, 'username' => 'root@pam!gaiacp', 'api_token' => 'secret-uuid']);
    }

    private function pending(array $attributes = []): Service
    {
        return Service::factory()->for($this->server)->create([
            'client_id' => Client::factory()->create(['email' => 'maria@example.com'])->id,
            'status' => ServiceStatus::Pending,
            'domain' => 'vps1.bakery.gr',
            'username' => 'bakery',
            'package' => 'debian12-small',
            ...$attributes,
        ]);
    }

    /** @return array<string, mixed> the VPS made for the service */
    private function vm(): array
    {
        return collect($this->proxmoxVms)->first(fn (array $vm) => ! $vm['template']);
    }

    public function test_connection_and_packages_are_the_templates(): void
    {
        $proxmox = app(ProvisionerFactory::class)->forServer($this->server);

        $this->assertSame('Proxmox VE 8.2.4', $proxmox->testConnection());
        $this->assertSame(['debian12-large', 'debian12-small'], $proxmox->packages());

        Http::assertSent(fn (Request $request) => $request->url() === 'https://pve.test:8006/api2/json/version'
            && $request->header('Authorization') === ['PVEAPIToken=root@pam!gaiacp=secret-uuid']);
    }

    public function test_create_clones_the_template_with_an_address_from_the_pool(): void
    {
        ProxmoxAddress::query()->create(['server_id' => $this->server->id, 'address' => '203.0.113.10', 'prefix' => 24, 'gateway' => '203.0.113.1']);
        $service = $this->pending();

        $this->lifecycle->create($service);

        $service->refresh();
        $this->assertSame(ServiceStatus::Active, $service->status);
        $vm = $this->vm();
        $this->assertSame(['pve1', 'vps1.bakery.gr', 'running', 'gaiacp;gaiacp-user-bakery'], [$vm['node'], $vm['name'], $vm['status'], $vm['tags']]);
        $this->assertSame(['root', $service->password, 'ip=203.0.113.10/24,gw=203.0.113.1', '1'], [$vm['config']['ciuser'], $vm['config']['cipassword'], $vm['config']['ipconfig0'], $vm['config']['onboot']]);
        $this->assertSame('1', $this->proxmoxParams['POST /nodes/pve1/qemu/9000/clone']['full']);
        $this->assertSame($service->id, ProxmoxAddress::query()->first()->service_id);

        $this->assertSame($service->password, $this->proxmoxUsers['bakery@pve']['password']);
        $this->assertSame(['path' => '/vms/100', 'users' => 'bakery@pve', 'roles' => 'PVEVMUser', 'propagate' => '0'], $this->proxmoxAcl[0]);
        $this->assertSame(AccountState::Active, app(ProvisionerFactory::class)->forServer($this->server)->accountState($service));
        Http::assertSent(fn (Request $request) => ! str_contains($request->url(), (string) $service->password));
    }

    public function test_a_server_without_a_pool_uses_dhcp_and_a_full_pool_refuses(): void
    {
        $this->lifecycle->create($first = $this->pending());
        $this->assertSame('ip=dhcp', $this->vm()['config']['ipconfig0']);

        ProxmoxAddress::query()->create(['server_id' => $this->server->id, 'address' => '203.0.113.10', 'prefix' => 24, 'service_id' => $first->id]);

        try {
            $this->lifecycle->create($this->pending(['username' => 'other', 'domain' => 'other.gr']));
            $this->fail('A full pool must refuse.');
        } catch (ProvisioningException $e) {
            $this->assertStringContainsString('no free IP address', $e->getMessage());
        }

        $this->assertCount(1, collect($this->proxmoxVms)->where('template', 0), 'nothing cloned');
    }

    public function test_a_failed_set_up_removes_the_clone_and_frees_the_address(): void
    {
        ProxmoxAddress::query()->create(['server_id' => $this->server->id, 'address' => '203.0.113.10', 'prefix' => 24]);
        $this->proxmoxFailures = ['#^POST /access/users$#' => [403, 'Permission check failed (/access/realm/pve, Realm.AllocateUser)']];
        $service = $this->pending();

        try {
            $this->lifecycle->create($service);
            $this->fail('The failure must surface.');
        } catch (ProvisioningException $e) {
            $this->assertStringContainsString('Realm.AllocateUser', $e->getMessage());
        }

        $this->assertSame(ServiceStatus::Pending, $service->refresh()->status);
        $this->assertCount(2, $this->proxmoxVms, 'only the templates are left');
        $this->assertNull(ProxmoxAddress::query()->first()->service_id);
        $this->assertTrue(ActivityLog::query()->where('event', 'provisioning.failed')->exists());
    }

    public function test_an_unknown_template_is_refused(): void
    {
        $this->expectExceptionMessage('There is no template nope');

        $this->lifecycle->create($this->pending(['package' => 'nope']));
    }

    public function test_suspend_stops_and_locks_and_unsuspend_starts_again(): void
    {
        $this->lifecycle->create($service = $this->pending());

        $this->lifecycle->suspend($service, Service::SUSPENDED_OVERDUE);

        $this->assertSame(['stopped', 'gaiacp;gaiacp-user-bakery;gaiacp-suspended'], [$this->vm()['status'], $this->vm()['tags']]);
        $this->assertSame(0, $this->proxmoxUsers['bakery@pve']['enable']);
        $this->assertSame(AccountState::Suspended, app(ProvisionerFactory::class)->forServer($this->server)->accountState($service));

        $this->lifecycle->unsuspend($service->refresh());

        $this->assertSame(['running', 'gaiacp;gaiacp-user-bakery'], [$this->vm()['status'], $this->vm()['tags']]);
        $this->assertSame(1, $this->proxmoxUsers['bakery@pve']['enable']);
        $this->assertSame(ServiceStatus::Active, $service->refresh()->status);
    }

    public function test_terminate_deletes_the_machine_its_user_and_frees_the_address(): void
    {
        ProxmoxAddress::query()->create(['server_id' => $this->server->id, 'address' => '203.0.113.10', 'prefix' => 24]);
        $this->lifecycle->create($service = $this->pending());

        $this->lifecycle->terminate($service);

        $this->assertCount(2, $this->proxmoxVms);
        $this->assertContains('POST /nodes/pve1/qemu/100/status/stop', $this->proxmoxCalls, 'stopped before it is deleted');
        $this->assertSame(['purge' => '1', 'destroy-unreferenced-disks' => '1'], $this->proxmoxParams['DELETE /nodes/pve1/qemu/100']);
        $this->assertArrayNotHasKey('bakery@pve', $this->proxmoxUsers);
        $this->assertNull(ProxmoxAddress::query()->first()->service_id);
        $this->assertSame(ServiceStatus::Terminated, $service->refresh()->status);
    }

    public function test_change_package_takes_the_new_templates_cpu_memory_and_grows_the_disk(): void
    {
        $this->lifecycle->create($service = $this->pending());

        $this->lifecycle->changePackage($service, 'debian12-large');

        $config = $this->vm()['config'];
        $this->assertSame(['4', '8192', 'local-lvm:base-9000-disk-0,size=40960M'], [(string) $config['cores'], (string) $config['memory'], $config['scsi0']]);
        $this->assertSame('debian12-large', $service->refresh()->package);

        // Back to the small one: CPU and memory follow, the disk stays as big as it is
        $this->lifecycle->changePackage($service, 'debian12-small');
        $this->assertSame(['1', '2048', 'local-lvm:base-9000-disk-0,size=40960M'], [(string) $this->vm()['config']['cores'], (string) $this->vm()['config']['memory'], $this->vm()['config']['scsi0']]);
    }

    public function test_change_password_replaces_the_proxmox_user_and_the_cloud_init_password(): void
    {
        $this->lifecycle->create($service = $this->pending());

        $this->lifecycle->changePassword($service, 'NewPassword123');

        $this->assertSame('NewPassword123', $this->proxmoxUsers['bakery@pve']['password']);
        $this->assertSame('NewPassword123', $this->vm()['config']['cipassword']);
        $this->assertSame(['username' => 'root', 'password' => 'NewPassword123'], $this->proxmoxParams['POST /nodes/pve1/qemu/100/agent/set-user-password'], 'a running VPS gets it at once through its guest agent');
        $this->assertCount(1, collect($this->proxmoxAcl)->where('users', 'bakery@pve'), 'its role on the machine comes back with it');
    }

    public function test_power_actions_are_logged_and_refused_on_a_suspended_vps(): void
    {
        $this->lifecycle->create($service = $this->pending());

        $this->lifecycle->power($service, 'shutdown');
        $this->assertFalse($this->lifecycle->isRunning($service));
        $this->lifecycle->power($service, 'start');
        $this->assertTrue($this->lifecycle->isRunning($service));
        $this->assertSame(2, ActivityLog::query()->where('event', 'account.power')->count());
        $this->assertTrue(ServiceLifecycleActions::controlsPower($service));

        $this->lifecycle->suspend($service, Service::SUSPENDED_OVERDUE);

        $this->expectException(ProvisioningException::class);
        $this->lifecycle->power($service->refresh(), 'start');
    }

    public function test_clients_get_the_power_buttons_unless_switched_off(): void
    {
        $this->lifecycle->create($service = $this->pending());

        $this->assertTrue(HostingSelfService::canControlPower($service));

        config(['gaiacp.proxmox.client_power' => false]);
        $this->assertFalse(HostingSelfService::canControlPower($service));
    }

    public function test_the_client_restarts_their_vps_from_its_page(): void
    {
        $this->lifecycle->create($service = $this->pending());
        $user = User::factory()->create(['role' => UserRole::Client, 'client_id' => $service->client_id, 'email' => $service->client->email]);

        Filament::setCurrentPanel('client');
        $this->actingAs($user, 'client');
        Livewire::test(ViewService::class, ['record' => $service->getRouteKey()])
            ->assertActionVisible('power_reboot')
            ->assertActionDoesNotExist('power_stop')
            ->callAction('power_shutdown')
            ->assertNotified(__('Your VPS was shut down.'));

        $this->assertSame('stopped', $this->vm()['status']);
        $this->assertSame($user->id, ActivityLog::query()->where('event', 'account.power')->sole()->user_id);
    }

    public function test_the_admin_force_stops_from_the_account_menu(): void
    {
        $this->lifecycle->create($service = $this->pending());
        $this->actingAs(User::factory()->create());

        Livewire::test(EditService::class, ['record' => $service->id])
            ->callAction('power_stop')
            ->assertNotified(__('Stopped'));

        $this->assertContains('POST /nodes/pve1/qemu/100/status/stop', $this->proxmoxCalls);
    }

    public function test_other_panels_have_no_power_control(): void
    {
        $cpanel = Service::factory()->for(Server::factory()->create())->create(['status' => ServiceStatus::Active, 'username' => 'shop']);

        $this->assertNull($this->lifecycle->isRunning($cpanel));
        $this->assertFalse(HostingSelfService::canControlPower($cpanel));

        $this->expectExceptionMessage('cannot start or stop');
        $this->lifecycle->power($cpanel, 'start');
    }

    public function test_accounts_lists_only_the_vps_made_by_gaiacp(): void
    {
        $this->lifecycle->create($this->pending());
        $this->proxmoxVms[150] = ['node' => 'pve1', 'name' => 'own-vm', 'template' => 0, 'status' => 'running', 'tags' => 'web', 'maxdisk' => 1, 'config' => []];

        $accounts = app(ProvisionerFactory::class)->forServer($this->server)->accounts();

        $this->assertCount(1, $accounts);
        $this->assertSame(['bakery', 'vps1.bakery.gr', false], [$accounts[0]->username, $accounts[0]->domain, $accounts[0]->suspended]);
    }

    public function test_usage_reports_the_disk_size(): void
    {
        $this->lifecycle->create($service = $this->pending());

        $usage = $this->lifecycle->usage($service);

        $this->assertSame(10 * 1024 ** 3, $usage->diskLimit);
        $this->assertNull($usage->diskUsed);
    }

    public function test_errors_carry_proxmoxs_reason(): void
    {
        $this->proxmoxFailures = ['#^GET /version$#' => [401, 'authentication failure']];

        $this->expectExceptionMessage('Proxmox /version: authentication failure');

        app(ProvisionerFactory::class)->forServer($this->server)->testConnection();
    }

    public function test_boot_disk_skips_cdroms_and_reads_sizes(): void
    {
        $this->assertSame(['virtio0', 32 * 1024 ** 3], ProxmoxProvisioner::bootDisk(['boot' => 'order=ide2;virtio0', 'ide2' => 'local:iso/x.iso,media=cdrom', 'virtio0' => 'ceph:vm-1-disk-0,size=32G']));
        $this->assertSame(['scsi0', 512 * 1024 ** 2], ProxmoxProvisioner::bootDisk(['scsi0' => 'local:vm-1-disk-0,iothread=1,size=512M']));
        $this->assertSame([null, null], ProxmoxProvisioner::bootDisk([]));
    }

    public function test_address_lines_and_ranges_are_parsed(): void
    {
        [$addresses, $invalid] = ProxmoxAddress::parse("# pool\n203.0.113.10/24 203.0.113.1\n203.0.113.20-203.0.113.22/24 203.0.113.1\n2001:db8::10/64 2001:db8::1\nnonsense\n10.0.0.1/40");

        $this->assertSame(['203.0.113.10', '203.0.113.20', '203.0.113.21', '203.0.113.22', '2001:db8::10'], array_column($addresses, 'address'));
        $this->assertSame(['nonsense', '10.0.0.1/40'], $invalid);
        $this->assertSame('ip6=2001:db8::10/64,gw6=2001:db8::1', (new ProxmoxAddress(['address' => '2001:db8::10', 'prefix' => 64, 'gateway' => '2001:db8::1']))->ipConfig());
    }
}
