<?php

namespace Tests\Feature;

use App\Actions\RecordPayment;
use App\Actions\ReversePayment;
use App\Enums\BillingCycle;
use App\Enums\ServiceStatus;
use App\Models\ActivityLog;
use App\Models\Payment;
use App\Models\Service;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\FakesWhm;
use Tests\TestCase;

class RecordPaymentTest extends TestCase
{
    use FakesWhm, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeWhm();
    }

    private function pay(Service $service, int $cents = 6000): Payment
    {
        return app(RecordPayment::class)->handle($service, $cents, CarbonImmutable::parse('2026-10-08'), 'bank_transfer', 'REF-1');
    }

    public function test_payment_advances_due_date_by_one_cycle(): void
    {
        $service = Service::factory()->create([
            'start_date' => '2025-10-20',
            'next_due_date' => '2026-10-20',
            'billing_cycle' => BillingCycle::Annually,
        ]);

        $payment = $this->pay($service);

        $this->assertSame('2027-10-20', $service->fresh()->next_due_date->toDateString());
        $this->assertSame('2026-10-20', $payment->due_date_before->toDateString());
        $this->assertSame('2027-10-20', $payment->due_date_after->toDateString());
        $this->assertSame(6000, $payment->amount_cents);
        $this->assertTrue(ActivityLog::where('event', 'payment.recorded')->where('service_id', $service->id)->exists());
    }

    public function test_monthly_service_anchored_on_the_31st_does_not_drift(): void
    {
        $service = Service::factory()->create([
            'start_date' => '2026-01-31',
            'next_due_date' => '2026-02-28',
            'billing_cycle' => BillingCycle::Monthly,
        ]);

        $this->pay($service);

        $this->assertSame('2026-03-31', $service->fresh()->next_due_date->toDateString());
    }

    public function test_payment_unsuspends_a_service_suspended_for_non_payment(): void
    {
        $this->travelTo('2026-10-08');

        $service = Service::factory()->create([
            'start_date' => '2025-09-01',
            'next_due_date' => '2026-09-01',
            'status' => ServiceStatus::Suspended,
            'suspension_reason' => Service::SUSPENDED_OVERDUE,
        ]);
        $this->whmAccounts[$service->username] = true;

        $this->pay($service);

        $service->refresh();
        $this->assertSame(ServiceStatus::Active, $service->status);
        $this->assertNull($service->suspension_reason);
        $this->assertFalse($this->whmAccounts[$service->username], 'unsuspended on WHM');
    }

    public function test_payment_is_kept_when_the_panel_cannot_unsuspend(): void
    {
        $this->travelTo('2026-10-08');
        $this->whmFailures['unsuspendacct'] = 'Server busy';

        $service = Service::factory()->create([
            'start_date' => '2025-09-01',
            'next_due_date' => '2026-09-01',
            'status' => ServiceStatus::Suspended,
            'suspension_reason' => Service::SUSPENDED_OVERDUE,
        ]);
        $this->whmAccounts[$service->username] = true;

        $this->pay($service);

        $service->refresh();
        $this->assertSame(1, $service->payments()->count());
        $this->assertSame('2027-09-01', $service->next_due_date->toDateString());
        $this->assertSame(ServiceStatus::Suspended, $service->status);
        $this->assertTrue(ActivityLog::where('event', 'provisioning.failed')->where('service_id', $service->id)->exists());
    }

    public function test_paying_a_suspended_domain_only_changes_its_status(): void
    {
        $this->travelTo('2026-10-08');

        $service = Service::factory()->domain()->create([
            'start_date' => '2025-09-01',
            'next_due_date' => '2026-09-01',
            'status' => ServiceStatus::Suspended,
            'suspension_reason' => Service::SUSPENDED_OVERDUE,
        ]);

        $this->pay($service, 1500);

        $this->assertSame(ServiceStatus::Active, $service->fresh()->status);
        $this->assertSame([], $this->whmCalls);
    }

    public function test_payment_keeps_a_manual_suspension(): void
    {
        $service = Service::factory()->create([
            'status' => ServiceStatus::Suspended,
            'suspension_reason' => 'abuse',
        ]);

        $this->pay($service);

        $this->assertSame(ServiceStatus::Suspended, $service->fresh()->status);
    }

    public function test_payment_that_still_leaves_it_overdue_keeps_it_suspended(): void
    {
        $this->travelTo('2026-10-08');

        $service = Service::factory()->create([
            'start_date' => '2026-06-01',
            'next_due_date' => '2026-07-01',
            'billing_cycle' => BillingCycle::Monthly,
            'status' => ServiceStatus::Suspended,
            'suspension_reason' => Service::SUSPENDED_OVERDUE,
        ]);

        $this->pay($service, 1000); // covers July only; August is still unpaid

        $this->assertSame(ServiceStatus::Suspended, $service->fresh()->status);
    }

    public function test_reversing_the_latest_payment_restores_the_due_date(): void
    {
        $service = Service::factory()->create(['start_date' => '2025-10-20', 'next_due_date' => '2026-10-20']);
        $payment = $this->pay($service);

        app(ReversePayment::class)->handle($payment);

        $this->assertSame('2026-10-20', $service->fresh()->next_due_date->toDateString());
        $this->assertModelMissing($payment);
    }

    public function test_only_the_latest_payment_can_be_reversed(): void
    {
        $service = Service::factory()->create(['start_date' => '2025-10-20', 'next_due_date' => '2026-10-20']);
        $first = $this->pay($service);
        $this->pay($service->fresh());

        $this->assertFalse(ReversePayment::canReverse($first));
        $this->expectException(RuntimeException::class);
        app(ReversePayment::class)->handle($first);
    }

    public function test_cannot_reverse_after_the_due_date_was_edited(): void
    {
        $service = Service::factory()->create(['start_date' => '2025-10-20', 'next_due_date' => '2026-10-20']);
        $payment = $this->pay($service);
        $service->fresh()->update(['next_due_date' => '2028-01-01']);

        $this->assertFalse(ReversePayment::canReverse($payment->fresh()));
    }
}
