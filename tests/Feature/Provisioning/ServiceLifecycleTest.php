<?php

namespace Tests\Feature\Provisioning;

use App\Actions\ServiceLifecycle;
use App\Enums\ServiceStatus;
use App\Models\ActivityLog;
use App\Models\Service;
use App\Provisioning\ProvisioningException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\FakesWhm;
use Tests\TestCase;

class ServiceLifecycleTest extends TestCase
{
    use FakesWhm, RefreshDatabase;

    private ServiceLifecycle $lifecycle;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeWhm();
        $this->lifecycle = app(ServiceLifecycle::class);
    }

    private function pending(array $attributes = []): Service
    {
        return Service::factory()->create([
            'status' => ServiceStatus::Pending,
            'domain' => 'acme-shop.gr',
            'username' => null,
            'package' => 'basic',
            ...$attributes,
        ]);
    }

    private function active(bool $suspendedOnPanel = false, array $attributes = []): Service
    {
        $service = Service::factory()->create($attributes);
        $this->whmAccounts[$service->username] = $suspendedOnPanel;

        return $service;
    }

    public function test_create_generates_credentials_and_creates_the_account(): void
    {
        $service = $this->pending();

        $this->lifecycle->create($service);

        $service->refresh();
        $this->assertSame(ServiceStatus::Active, $service->status);
        $this->assertSame('acmeshop', $service->username);
        $this->assertSame(16, strlen($service->password));
        $this->assertSame(['listaccts', 'createacct'], $this->whmFunctions());

        $params = $this->whmCalls[1][1];
        $this->assertSame(['acmeshop', 'acme-shop.gr', 'basic', $service->client->email], [$params['username'], $params['domain'], $params['plan'], $params['contactemail']]);
        $this->assertSame($service->password, $params['password']);
        $this->assertTrue(ActivityLog::where('event', 'account.created')->exists());
    }

    public function test_create_keeps_a_username_and_password_entered_by_hand(): void
    {
        $service = $this->pending(['username' => 'acme', 'password' => 'Chosen-Pass-1']);

        $this->lifecycle->create($service);

        $this->assertSame('acme', $this->whmCalls[1][1]['username']);
        $this->assertSame('Chosen-Pass-1', $this->whmCalls[1][1]['password']);
    }

    public function test_create_retry_after_a_timeout_does_not_create_twice(): void
    {
        $service = $this->pending(['username' => 'acme']);
        $this->whmAccounts['acme'] = false; // the first attempt did get through
        $this->whmDomains['acme'] = 'ACME-shop.gr';

        $this->lifecycle->create($service);

        $this->assertNotContains('createacct', $this->whmFunctions());
        $this->assertSame(ServiceStatus::Active, $service->fresh()->status);
    }

    public function test_create_refuses_a_username_taken_by_another_account_on_the_server(): void
    {
        $service = $this->pending(['username' => 'acme']);
        $this->whmAccounts['acme'] = false;
        $this->whmDomains['acme'] = 'someone-else.com';

        try {
            $this->lifecycle->create($service);
            $this->fail('expected an exception');
        } catch (ProvisioningException $e) {
            $this->assertStringContainsString('already has a different account', $e->getMessage());
            $this->assertStringContainsString('someone-else.com', $e->getMessage());
        }

        $this->assertNotContains('createacct', $this->whmFunctions());
        $this->assertSame(ServiceStatus::Pending, $service->fresh()->status);
    }

    public function test_failed_create_stays_pending_and_is_logged(): void
    {
        $this->whmFailures['createacct'] = 'The domain acme-shop.gr already exists.';
        $service = $this->pending();

        try {
            $this->lifecycle->create($service);
            $this->fail('expected an exception');
        } catch (ProvisioningException $e) {
            $this->assertStringContainsString('already exists', $e->getMessage());
        }

        $this->assertSame(ServiceStatus::Pending, $service->fresh()->status);
        $log = ActivityLog::where('event', 'provisioning.failed')->sole();
        $this->assertStringContainsString('account.created failed', $log->description);
    }

    public function test_terminated_services_cannot_be_created(): void
    {
        $this->expectException(ProvisioningException::class);

        $this->lifecycle->create(Service::factory()->create(['status' => ServiceStatus::Terminated]));
    }

    public function test_create_an_active_service_again_after_moving_it_to_another_server(): void
    {
        Mail::fake();
        // Moved: the new server has no account yet.
        $service = Service::factory()->create(['username' => 'acme', 'password' => 'Kept-Pass-1', 'domain' => 'acme-shop.gr']);

        $this->lifecycle->create($service, sendWelcome: false);

        $this->assertSame(['listaccts', 'createacct'], $this->whmFunctions());
        $this->assertSame(['acme', 'Kept-Pass-1'], [$this->whmCalls[1][1]['username'], $this->whmCalls[1][1]['password']]);
        $this->assertSame(ServiceStatus::Active, $service->fresh()->status);
        $this->assertTrue(ActivityLog::where('event', 'account.created')->exists());
        Mail::assertNothingSent();

        // Running it again finds the account and creates nothing.
        $this->lifecycle->create($service->fresh(), sendWelcome: false);

        $this->assertSame(1, collect($this->whmFunctions())->filter(fn ($f) => $f === 'createacct')->count());
    }

    public function test_a_suspended_service_created_again_is_suspended_on_the_new_server(): void
    {
        $service = Service::factory()->create(['username' => 'acme', 'status' => ServiceStatus::Suspended, 'suspension_reason' => 'overdue']);

        $this->lifecycle->create($service, sendWelcome: false);

        $this->assertTrue($this->whmAccounts['acme']);
        $this->assertSame(ServiceStatus::Suspended, $service->fresh()->status);
    }

    public function test_suspend_and_unsuspend(): void
    {
        $service = $this->active();

        $this->lifecycle->suspend($service, 'abuse report');

        $service->refresh();
        $this->assertSame(ServiceStatus::Suspended, $service->status);
        $this->assertSame('abuse report', $service->suspension_reason);
        $this->assertTrue($this->whmAccounts[$service->username]);
        $this->assertSame('abuse report', $this->whmCalls[1][1]['reason']);

        $this->lifecycle->unsuspend($service);

        $service->refresh();
        $this->assertSame(ServiceStatus::Active, $service->status);
        $this->assertNull($service->suspension_reason);
        $this->assertFalse($this->whmAccounts[$service->username]);
    }

    public function test_suspend_twice_calls_the_panel_once(): void
    {
        $service = $this->active();

        $this->lifecycle->suspend($service, Service::SUSPENDED_OVERDUE);
        $this->lifecycle->suspend($service->fresh(), Service::SUSPENDED_OVERDUE);

        $this->assertSame(1, collect($this->whmFunctions())->filter(fn ($f) => $f === 'suspendacct')->count());
    }

    public function test_suspend_skips_the_api_call_when_already_suspended_on_the_panel(): void
    {
        $service = $this->active(suspendedOnPanel: true);

        $this->lifecycle->suspend($service, 'x');

        $this->assertNotContains('suspendacct', $this->whmFunctions());
        $this->assertSame(ServiceStatus::Suspended, $service->fresh()->status);
    }

    public function test_failed_suspend_leaves_the_service_active(): void
    {
        $this->whmFailures['suspendacct'] = 'Permission denied';
        $service = $this->active();

        $this->expectException(ProvisioningException::class);

        try {
            $this->lifecycle->suspend($service, 'x');
        } finally {
            $this->assertSame(ServiceStatus::Active, $service->fresh()->status);
        }
    }

    public function test_terminate_removes_the_account(): void
    {
        $service = $this->active();

        $this->lifecycle->terminate($service);

        $this->assertSame(ServiceStatus::Terminated, $service->fresh()->status);
        $this->assertArrayNotHasKey($service->username, $this->whmAccounts);
        $this->assertContains('removeacct', $this->whmFunctions());
    }

    public function test_terminate_when_the_account_is_already_gone(): void
    {
        $service = Service::factory()->create(); // not on the fake server

        $this->lifecycle->terminate($service);

        $this->assertNotContains('removeacct', $this->whmFunctions());
        $this->assertSame(ServiceStatus::Terminated, $service->fresh()->status);
    }

    public function test_terminating_a_pending_service_never_touches_the_panel(): void
    {
        $service = $this->pending();

        $this->lifecycle->terminate($service);

        $this->assertSame([], $this->whmCalls);
        $this->assertSame(ServiceStatus::Terminated, $service->fresh()->status);
    }

    public function test_change_package(): void
    {
        $service = $this->active();

        $this->lifecycle->changePackage($service, 'pro');

        $this->assertSame('pro', $service->fresh()->package);
        $this->assertSame(['changepackage', ['user' => $service->username, 'pkg' => 'pro']], $this->whmCalls[0]);
    }

    public function test_maintenance_and_domain_services_only_change_status(): void
    {
        $maintenance = Service::factory()->maintenance()->create();
        $domain = Service::factory()->domain()->create();

        $this->lifecycle->suspend($maintenance, 'x');
        $this->lifecycle->terminate($domain);

        $this->assertSame([], $this->whmCalls);
        $this->assertSame(ServiceStatus::Suspended, $maintenance->fresh()->status);
        $this->assertSame(ServiceStatus::Terminated, $domain->fresh()->status);
    }
}
