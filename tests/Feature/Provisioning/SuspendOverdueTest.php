<?php

namespace Tests\Feature\Provisioning;

use App\Enums\ServiceStatus;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\FakesWhm;
use Tests\TestCase;

class SuspendOverdueTest extends TestCase
{
    use FakesWhm, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeWhm();
        config(['gaiacp.automation.suspend_after_days' => 7]);
    }

    private function hosting(string $due): Service
    {
        $service = Service::factory()->dueOn($due)->create();
        $this->whmAccounts[$service->username] = false;

        return $service;
    }

    public function test_suspends_hosting_overdue_past_the_grace_period(): void
    {
        $late = $this->hosting('2026-09-30');     // 8 days late
        $graceDay = $this->hosting('2026-10-01'); // exactly 7 days: suspend
        $recent = $this->hosting('2026-10-05');   // 3 days late
        $domain = Service::factory()->domain()->dueOn('2026-09-01')->create();

        $this->artisan('gaiacp:suspend-overdue', ['--date' => '2026-10-08'])->assertSuccessful();

        $this->assertSame(ServiceStatus::Suspended, $late->fresh()->status);
        $this->assertSame(Service::SUSPENDED_OVERDUE, $late->fresh()->suspension_reason);
        $this->assertSame(ServiceStatus::Suspended, $graceDay->fresh()->status);
        $this->assertSame(ServiceStatus::Active, $recent->fresh()->status);
        $this->assertSame(ServiceStatus::Active, $domain->fresh()->status);
        $this->assertTrue($this->whmAccounts[$late->username]);
    }

    public function test_off_when_not_configured(): void
    {
        config(['gaiacp.automation.suspend_after_days' => null]);
        $service = $this->hosting('2026-01-01');

        $this->artisan('gaiacp:suspend-overdue', ['--date' => '2026-10-08'])
            ->expectsOutputToContain('Auto-suspend is off')
            ->assertSuccessful();

        $this->assertSame(ServiceStatus::Active, $service->fresh()->status);
    }

    public function test_dry_run_changes_nothing(): void
    {
        $service = $this->hosting('2026-09-01');

        $this->artisan('gaiacp:suspend-overdue', ['--date' => '2026-10-08', '--dry-run' => true])
            ->expectsOutputToContain('[dry run] would suspend')
            ->assertSuccessful();

        $this->assertSame(ServiceStatus::Active, $service->fresh()->status);
        $this->assertSame([], $this->whmCalls);
    }

    public function test_one_failure_does_not_stop_the_rest_and_the_command_fails(): void
    {
        $broken = $this->hosting('2026-09-01');
        $ok = $this->hosting('2026-09-02');
        unset($this->whmAccounts[$broken->username]);
        $this->whmAccounts[$broken->username] = false;
        $this->whmFailures['suspendacct'] = 'boom';

        $this->artisan('gaiacp:suspend-overdue', ['--date' => '2026-10-08'])->assertFailed();

        $this->assertSame(ServiceStatus::Active, $broken->fresh()->status);
        $this->assertSame(ServiceStatus::Active, $ok->fresh()->status);
    }

    public function test_do_not_suspend_until_overrides_the_grace_period(): void
    {
        $spared = $this->hosting('2026-09-01');
        $spared->update(['override_suspend_until' => '2026-10-08']);
        $expired = $this->hosting('2026-09-01');
        $expired->update(['override_suspend_until' => '2026-10-07']);

        $this->artisan('gaiacp:suspend-overdue', ['--date' => '2026-10-08'])->assertSuccessful();

        $this->assertSame(ServiceStatus::Active, $spared->fresh()->status);
        $this->assertSame(ServiceStatus::Suspended, $expired->fresh()->status);
    }

    public function test_auto_terminate_at_the_end_of_the_cycle(): void
    {
        $ended = $this->hosting('2026-10-08');
        $ended->update(['auto_terminate' => true, 'auto_terminate_reason' => 'Client moved away']);
        $running = $this->hosting('2026-10-09');
        $running->update(['auto_terminate' => true]);
        $kept = $this->hosting('2026-09-01');

        $this->artisan('gaiacp:terminate-scheduled', ['--date' => '2026-10-08'])->assertSuccessful();

        $this->assertSame(ServiceStatus::Terminated, $ended->fresh()->status);
        $this->assertArrayNotHasKey($ended->username, $this->whmAccounts);
        $this->assertSame(ServiceStatus::Active, $running->fresh()->status);
        $this->assertSame(ServiceStatus::Active, $kept->fresh()->status);
        $this->assertDatabaseHas('activity_logs', ['event' => 'service.auto_terminated', 'service_id' => $ended->id]);
    }
}
