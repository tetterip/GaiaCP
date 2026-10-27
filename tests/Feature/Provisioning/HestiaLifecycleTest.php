<?php

namespace Tests\Feature\Provisioning;

use App\Actions\ServiceLifecycle;
use App\Enums\ServiceStatus;
use App\Models\Client;
use App\Models\ModuleLog;
use App\Models\Server;
use App\Models\Service;
use App\Provisioning\PackageOptions;
use App\Provisioning\ProvisionerFactory;
use App\Provisioning\ProvisioningException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\FakesHestia;
use Tests\TestCase;

/** HestiaCP: one user per hosting service, with the service's domain, over the access key or password API. */
class HestiaLifecycleTest extends TestCase
{
    use FakesHestia, RefreshDatabase;

    private ServiceLifecycle $lifecycle;

    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeHestia();
        $this->lifecycle = app(ServiceLifecycle::class);
        $this->server = Server::factory()->hestia()->create(['hostname' => 'hst.test']);
    }

    private function pending(array $attributes = []): Service
    {
        return Service::factory()->for($this->server)->create([
            'client_id' => Client::factory()->create(['first_name' => 'Maria "M"', 'last_name' => 'Papa', 'email' => 'maria@example.com'])->id,
            'status' => ServiceStatus::Pending,
            'domain' => 'bakery.gr',
            'username' => null,
            'package' => 'Starter',
            ...$attributes,
        ]);
    }

    public function test_connection_and_packages_with_an_access_key(): void
    {
        $hestia = app(ProvisionerFactory::class)->forServer($this->server);

        $this->assertSame('HestiaCP 1.9.4', $hestia->testConnection());
        $this->assertSame(['default', 'Starter', 'Business'], $hestia->packages(), 'the admin\'s "system" package is left out');
        $this->assertSame(['default', 'Starter', 'Business'], PackageOptions::forServer($this->server->id));

        Http::assertSent(fn (Request $request) => $request->url() === 'https://hst.test:8083/api/'
            && $request['access_key'] === 'AKEY0000000000000001'
            && $request['secret_key'] === str_repeat('s', 40)
            && ! isset($request['user'])
            && $request['cmd'] === 'v-list-sys-info'
            && $request['arg1'] === 'json');
    }

    public function test_create_adds_the_user_and_its_domain(): void
    {
        $service = $this->pending(['domain' => 'φούρνος.gr']);

        $this->lifecycle->create($service);

        $service->refresh();
        $this->assertSame(ServiceStatus::Active, $service->status);
        $this->assertSame(['v-list-user', 'v-add-user', 'v-add-domain'], $this->hestiaCommands());

        $user = $this->hestiaUsers[$service->username];
        $this->assertSame(['maria@example.com', 'Starter', 'Maria M Papa', $service->password], [$user['CONTACT'], $user['PACKAGE'], $user['NAME'], $user['password']]);
        $this->assertSame(['xn--yxadbght4c.gr'], $user['domains'], 'domains go to HestiaCP in punycode');
        Http::assertSent(fn (Request $request) => ! str_contains($request->url(), (string) $service->password));
    }

    public function test_a_failed_domain_removes_the_new_user(): void
    {
        $this->hestiaFailures['v-add-domain'] = [4, 'web domain bakery.gr exists'];
        $service = $this->pending();

        try {
            $this->lifecycle->create($service);
            $this->fail('expected an exception');
        } catch (ProvisioningException $e) {
            $this->assertSame('HestiaCP v-add-domain: web domain bakery.gr exists', $e->getMessage());
        }

        $this->assertSame([], $this->hestiaUsers);
        $this->assertSame('v-delete-user', last($this->hestiaCommands()));
        $this->assertSame(ServiceStatus::Pending, $service->fresh()->status);
    }

    public function test_create_retry_finds_its_own_user(): void
    {
        $service = $this->pending(['username' => 'bakery']);
        $this->hestiaUsers['bakery'] = ['SUSPENDED' => 'no', 'PACKAGE' => 'Starter', 'domains' => ['bakery.gr']];

        $this->lifecycle->create($service);

        $this->assertNotContains('v-add-user', $this->hestiaCommands());
        $this->assertSame(ServiceStatus::Active, $service->fresh()->status);
    }

    public function test_create_refuses_a_user_with_another_domain(): void
    {
        $service = $this->pending(['username' => 'bakery']);
        $this->hestiaUsers['bakery'] = ['SUSPENDED' => 'no', 'PACKAGE' => 'Starter', 'domains' => ['other.gr']];

        $this->expectExceptionMessage('already has a different account for this service (other.gr)');

        $this->lifecycle->create($service);
    }

    public function test_suspend_unsuspend_change_package_password_terminate(): void
    {
        $service = $this->pending();
        $this->lifecycle->create($service);
        $username = $service->refresh()->username;

        $this->lifecycle->suspend($service, Service::SUSPENDED_OVERDUE);
        $this->assertSame('yes', $this->hestiaUsers[$username]['SUSPENDED']);
        $this->assertSame(ServiceStatus::Suspended, $service->fresh()->status);

        $this->lifecycle->unsuspend($service->fresh());
        $this->assertSame('no', $this->hestiaUsers[$username]['SUSPENDED']);

        $this->lifecycle->changePackage($service->fresh(), 'Business');
        $this->assertSame('Business', $this->hestiaUsers[$username]['PACKAGE']);

        $this->lifecycle->changePassword($service->fresh(), 'NewSecret123');
        $this->assertSame('NewSecret123', $this->hestiaUsers[$username]['password']);
        $this->assertDatabaseMissing('activity_logs', ['description' => 'NewSecret123']);

        $this->lifecycle->terminate($service->fresh());
        $this->assertArrayNotHasKey($username, $this->hestiaUsers);
        $this->assertSame(ServiceStatus::Terminated, $service->fresh()->status);
    }

    public function test_unknown_package_is_reported(): void
    {
        $service = $this->pending();
        $this->lifecycle->create($service);

        $this->expectExceptionMessage("HestiaCP v-change-user-package: package Gold doesn't exist");

        $this->lifecycle->changePackage($service->fresh(), 'Gold');
    }

    public function test_a_password_starting_with_a_slash_is_refused(): void
    {
        $service = $this->pending();
        $this->lifecycle->create($service);

        $this->expectExceptionMessage('cannot start with "/"');

        $this->lifecycle->changePassword($service->fresh(), '/tmp/secrets');
    }

    public function test_the_admin_password_login_and_its_plain_text_errors(): void
    {
        $this->hestiaPasswordLogin = true;
        $this->server->update(['username' => 'admin', 'api_token' => 'AdminPass1']);
        $service = $this->pending(['username' => 'bakery']);

        $this->lifecycle->create($service);

        $this->assertSame(ServiceStatus::Active, $service->fresh()->status, 'a missing user is told by "doesn\'t exist"');
        Http::assertSent(fn (Request $request) => $request['user'] === 'admin' && $request['password'] === 'AdminPass1' && ! isset($request['access_key']));

        $this->hestiaFailures['v-suspend-user'] = [5, 'user bakery is suspended'];
        $this->expectExceptionMessage('HestiaCP v-suspend-user: user bakery is suspended');

        $this->lifecycle->suspend($service->fresh(), Service::SUSPENDED_OVERDUE);
    }

    public function test_usage_comes_from_the_user(): void
    {
        $service = $this->pending(['username' => 'bakery', 'status' => ServiceStatus::Active]);
        $this->hestiaUsers['bakery'] = ['SUSPENDED' => 'no', 'PACKAGE' => 'Starter', 'domains' => ['bakery.gr'],
            'U_DISK' => '150', 'DISK_QUOTA' => '1000', 'U_BANDWIDTH' => '2048', 'BANDWIDTH' => 'unlimited',
            'U_MAIL_ACCOUNTS' => '3', 'MAIL_ACCOUNTS' => '10', 'U_DATABASES' => '1', 'DATABASES' => 'unlimited',
            'U_WEB_DOMAINS' => '1', 'WEB_DOMAINS' => '1', 'U_DISK_DB' => '12'];

        $usage = $this->lifecycle->usage($service);

        $mb = 1024 ** 2;
        $this->assertSame([150 * $mb, 1000 * $mb, 2048 * $mb, null], [$usage->diskUsed, $usage->diskLimit, $usage->bandwidthUsed, $usage->bandwidthLimit]);
        $this->assertSame(['email_accounts' => [3, 10], 'databases' => [1, null], 'sites' => [1, 1], 'database_disk' => [12 * $mb, null]], $usage->counts);
    }

    public function test_panel_login_is_the_login_page(): void
    {
        $service = $this->pending(['username' => 'bakery', 'status' => ServiceStatus::Active]);

        $this->assertSame('https://hst.test:8083/login/', $this->lifecycle->loginUrl($service, '203.0.113.9'));
        $this->assertSame([], $this->hestiaCalls, 'HestiaCP has no one-time login to ask for');
    }

    public function test_the_module_log_masks_passwords_and_the_key(): void
    {
        config(['gaiacp.debug.module_log' => true]);
        $service = $this->pending();

        $this->lifecycle->create($service);

        $logs = ModuleLog::where('module', 'hestia')->orderBy('id')->get();
        $this->assertSame(['v-list-user', 'v-add-user', 'v-add-domain'], $logs->pluck('action')->all());
        $all = $logs->map(fn (ModuleLog $l) => $l->request.$l->response)->implode('');
        $this->assertStringContainsString('cmd=v-add-user', $all);
        foreach ([$service->fresh()->password, str_repeat('s', 40)] as $secret) {
            $this->assertStringNotContainsString($secret, $all);
        }
    }
}
