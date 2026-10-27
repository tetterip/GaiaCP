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
use App\Provisioning\UsernameGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\FakesDirectAdmin;
use Tests\TestCase;

/** DirectAdmin: one user per hosting service, created by the admin or a reseller over the legacy API. */
class DirectAdminLifecycleTest extends TestCase
{
    use FakesDirectAdmin, RefreshDatabase;

    private ServiceLifecycle $lifecycle;

    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeDirectAdmin();
        $this->lifecycle = app(ServiceLifecycle::class);
        $this->server = Server::factory()->directAdmin()->create(['hostname' => 'da.test']);
    }

    private function pending(array $attributes = []): Service
    {
        return Service::factory()->for($this->server)->create([
            'client_id' => Client::factory()->create(['email' => 'maria@example.com'])->id,
            'status' => ServiceStatus::Pending,
            'domain' => 'bakery-shop.gr',
            'username' => null,
            'package' => 'basic',
            ...$attributes,
        ]);
    }

    public function test_connection_and_packages_with_basic_auth(): void
    {
        $this->daUsers['someone'] = ['domain' => 'x.gr', 'suspended' => 'no'];
        $da = app(ProvisionerFactory::class)->forServer($this->server);

        $this->assertSame('DirectAdmin, 1 account(s) owned by admin', $da->testConnection());
        $this->assertSame(['basic', 'pro'], $da->packages());
        $this->assertSame(['basic', 'pro'], PackageOptions::forServer($this->server->id));

        Http::assertSent(fn (Request $request) => $request->url() === 'https://da.test:2222/CMD_API_SHOW_USERS'
            && $request->method() === 'GET'
            && $request->header('Authorization')[0] === 'Basic '.base64_encode('admin:LOGINKEY'.str_repeat('k', 56)));
    }

    public function test_create_makes_the_user_on_the_logins_first_ip(): void
    {
        $service = $this->pending(['domain' => 'φούρνος.gr']);

        $this->lifecycle->create($service);

        $service->refresh();
        $this->assertSame(ServiceStatus::Active, $service->status);
        $this->assertSame(['SHOW_USERS', 'SHOW_RESELLER_IPS', 'ACCOUNT_USER'], $this->daCommands());

        [, $method, $params] = $this->daCalls[2];
        $this->assertSame('POST', $method);
        $this->assertSame(
            ['create', 'Submit', $service->username, 'maria@example.com', 'xn--yxadbght4c.gr', 'basic', '203.0.113.10', 'no', $service->password, $service->password],
            [$params['action'], $params['add'], $params['username'], $params['email'], $params['domain'], $params['package'], $params['ip'], $params['notify'], $params['passwd'], $params['passwd2']],
        );
        Http::assertSent(fn (Request $request) => ! str_contains($request->url(), (string) $service->password));
    }

    public function test_the_servers_ip_for_new_accounts_wins(): void
    {
        $this->server->update(['shared_ip' => '198.51.100.7']);

        $this->lifecycle->create($this->pending());

        $this->assertNotContains('SHOW_RESELLER_IPS', $this->daCommands());
        $this->assertSame('198.51.100.7', $this->daCalls[1][2]['ip']);
    }

    public function test_generated_usernames_fit_directadmins_ten_characters(): void
    {
        $service = $this->pending(['domain' => 'averyverylongbakery.gr']);

        $this->lifecycle->create($service);

        $this->assertSame('averyveryl', $service->fresh()->username);
        $this->assertSame(10, UsernameGenerator::maxLength('directadmin'));
        $this->assertSame(UsernameGenerator::MAX_LENGTH, UsernameGenerator::maxLength('cpanel'));
    }

    public function test_create_retry_finds_its_own_user_and_refuses_another_domain(): void
    {
        $service = $this->pending(['username' => 'bakery']);
        $this->daUsers['bakery'] = ['domain' => 'bakery-shop.gr', 'suspended' => 'no'];

        $this->lifecycle->create($service);

        $this->assertNotContains('ACCOUNT_USER', $this->daCommands());
        $this->assertSame(ServiceStatus::Active, $service->fresh()->status);

        $other = $this->pending(['username' => 'taken', 'domain' => 'mine.gr']);
        $this->daUsers['taken'] = ['domain' => 'theirs.gr', 'suspended' => 'no'];

        $this->expectExceptionMessage('already has a different account for this service (theirs.gr)');

        $this->lifecycle->create($other);
    }

    public function test_suspend_unsuspend_change_package_password_terminate(): void
    {
        $service = $this->pending();
        $this->lifecycle->create($service);
        $username = $service->refresh()->username;

        $this->lifecycle->suspend($service, Service::SUSPENDED_OVERDUE);
        $this->assertSame('yes', $this->daUsers[$username]['suspended']);
        $this->assertSame(['suspend' => 'Suspend', 'select0' => $username], last($this->daCalls)[2]);
        $this->assertSame(ServiceStatus::Suspended, $service->fresh()->status);

        $this->lifecycle->unsuspend($service->fresh());
        $this->assertSame('no', $this->daUsers[$username]['suspended']);

        $this->lifecycle->changePackage($service->fresh(), 'pro');
        $this->assertSame('pro', $this->daUsers[$username]['package']);
        $this->assertSame(['action' => 'package', 'user' => $username, 'package' => 'pro'], last($this->daCalls)[2]);

        $this->lifecycle->changePassword($service->fresh(), 'NewSecret123');
        $this->assertSame('NewSecret123', $this->daUsers[$username]['password']);

        $this->lifecycle->terminate($service->fresh());
        $this->assertArrayNotHasKey($username, $this->daUsers);
        $this->assertSame(['confirmed' => 'Confirm', 'delete' => 'yes', 'select0' => $username], last($this->daCalls)[2]);
        $this->assertSame(ServiceStatus::Terminated, $service->fresh()->status);
    }

    public function test_directadmin_errors_are_reported_with_their_details(): void
    {
        $service = $this->pending(['package' => 'gold']);

        try {
            $this->lifecycle->create($service);
            $this->fail('expected an exception');
        } catch (ProvisioningException $e) {
            $this->assertSame('DirectAdmin ACCOUNT_USER: Unable to Create User: Package does not exist', $e->getMessage());
        }

        $this->assertSame(ServiceStatus::Pending, $service->fresh()->status);
    }

    public function test_a_rejected_login_or_a_web_page_is_explained(): void
    {
        $this->daRawResponse = Http::response('Unauthorized', 401);

        try {
            app(ProvisionerFactory::class)->forServer($this->server)->testConnection();
            $this->fail('expected an exception');
        } catch (ProvisioningException $e) {
            $this->assertSame('DirectAdmin rejected the login for da.test (HTTP 401).', $e->getMessage());
        }
    }

    public function test_an_html_reply_means_the_wrong_port_or_no_api_access(): void
    {
        $this->daRawResponse = Http::response('<html>login</html>', 200, ['Content-Type' => 'text/html']);

        $this->expectExceptionMessage('the server answered with a web page, not the API');

        app(ProvisionerFactory::class)->forServer($this->server)->testConnection();
    }

    public function test_usage_comes_from_the_users_usage_and_config(): void
    {
        $service = $this->pending(['username' => 'bakery', 'status' => ServiceStatus::Active]);
        $this->daUsers['bakery'] = ['domain' => 'bakery-shop.gr', 'suspended' => 'no', 'quota' => '1000', 'bandwidth' => 'unlimited',
            'nemails' => '10', 'mysql' => 'unlimited', 'vdomains' => '1', 'domainptr' => '0', 'nsubdomains' => 'unlimited'];
        $this->daUsage['bakery'] = ['quota' => '150.5', 'bandwidth' => '2048', 'nemails' => '3', 'mysql' => '1', 'vdomains' => '1', 'domainptr' => '0', 'nsubdomains' => '2'];

        $usage = $this->lifecycle->usage($service);

        $mb = 1024 ** 2;
        $this->assertSame([(int) round(150.5 * $mb), 1000 * $mb, 2048 * $mb, null], [$usage->diskUsed, $usage->diskLimit, $usage->bandwidthUsed, $usage->bandwidthLimit]);
        $this->assertSame(['email_accounts' => [3, 10], 'databases' => [1, null], 'sites' => [1, 1], 'parked_domains' => [0, 0], 'subdomains' => [2, null]], $usage->counts);
    }

    public function test_panel_login_is_the_login_page(): void
    {
        $service = $this->pending(['username' => 'bakery', 'status' => ServiceStatus::Active]);

        $this->assertSame('https://da.test:2222/', $this->lifecycle->loginUrl($service, '203.0.113.9'));
        $this->assertSame([], $this->daCalls);
    }

    public function test_the_module_log_masks_passwords_and_the_login_key(): void
    {
        config(['gaiacp.debug.module_log' => true]);
        $service = $this->pending();

        $this->lifecycle->create($service);

        $logs = ModuleLog::where('module', 'directadmin')->orderBy('id')->get();
        $this->assertSame(['SHOW_USERS', 'SHOW_RESELLER_IPS', 'ACCOUNT_USER'], $logs->pluck('action')->all());
        $all = $logs->map(fn (ModuleLog $l) => $l->request.$l->response)->implode('');
        $this->assertStringContainsString('action=create', $all);
        foreach ([$service->fresh()->password, str_repeat('k', 56)] as $secret) {
            $this->assertStringNotContainsString($secret, $all);
        }
    }
}
