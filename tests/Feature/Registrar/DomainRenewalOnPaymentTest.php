<?php

namespace Tests\Feature\Registrar;

use App\Actions\RecordPayment;
use App\Enums\BillingCycle;
use App\Enums\ServiceStatus;
use App\Filament\Resources\Services\Pages\EditService;
use App\Models\ActivityLog;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Plugins\EasyGr\EasyRegistrar;
use Tests\FakesEasy;
use Tests\TestCase;

class DomainRenewalOnPaymentTest extends TestCase
{
    use FakesEasy, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeEasy();
    }

    private function pay(Service $service): RecordPayment
    {
        $recorder = app(RecordPayment::class);
        $recorder->handle($service, 1500, CarbonImmutable::parse('2026-10-08'), 'bank_transfer');

        return $recorder;
    }

    public function test_paying_an_active_domain_renews_it_for_one_cycle(): void
    {
        $service = Service::factory()->domain()->create(['domain' => 'acme.gr', 'billing_cycle' => BillingCycle::Biennially]);
        $this->easyDomains['acme.gr'] = ['expires' => '2026-11-01'];

        $recorder = $this->pay($service);

        $this->assertNull($recorder->renewalError);
        $this->assertSame([[EasyRegistrar::RENEW, ['domain' => 'acme.gr', 'years' => '2']]], $this->easyCalls);
        $this->assertSame('2028-11-01', $this->easyDomains['acme.gr']['expires']);
        $this->assertTrue(ActivityLog::where('event', 'domain.renewed')->exists());
    }

    public function test_longest_tld_picks_the_registrar(): void
    {
        $service = Service::factory()->domain()->create(['domain' => 'shop.com.gr']);
        $this->easyDomains['shop.com.gr'] = ['expires' => '2026-11-01'];

        $this->pay($service);

        $this->assertSame([EasyRegistrar::RENEW], $this->easyActions());
    }

    public function test_first_payment_of_a_pending_domain_does_not_renew(): void
    {
        $service = Service::factory()->domain()->create(['domain' => 'acme.gr', 'status' => ServiceStatus::Pending]);

        $this->pay($service);

        $this->assertSame([], $this->easyCalls);
    }

    public function test_monthly_billed_domain_is_not_renewed_every_month(): void
    {
        $service = Service::factory()->domain()->create(['domain' => 'acme.gr', 'billing_cycle' => BillingCycle::Monthly]);

        $this->pay($service);

        $this->assertSame([], $this->easyCalls);
    }

    public function test_hosting_payments_never_call_the_registrar(): void
    {
        $service = Service::factory()->create(['domain' => 'acme.gr']);

        $this->pay($service);

        $this->assertSame([], $this->easyCalls);
    }

    public function test_registrar_failure_keeps_the_payment(): void
    {
        $service = Service::factory()->domain()->create(['domain' => 'acme.gr', 'start_date' => '2025-11-01', 'next_due_date' => '2026-11-01']);
        $this->easyFailures[EasyRegistrar::RENEW] = ['2304' => 'Status prohibits renew'];

        $recorder = $this->pay($service);

        $this->assertSame('easy.gr: 2304 - Status prohibits renew', $recorder->renewalError);
        $this->assertSame('2027-11-01', $service->refresh()->next_due_date->toDateString());
        $this->assertSame(1, $service->payments()->count());
        $this->assertTrue(ActivityLog::where('event', 'provisioning.failed')->exists());
    }

    public function test_no_registrar_for_the_tld_is_reported(): void
    {
        $service = Service::factory()->domain()->create(['domain' => 'acme.de']);

        $recorder = $this->pay($service);

        $this->assertStringContainsString('No active registrar handles the TLD of acme.de', $recorder->renewalError);
        $this->assertSame([], $this->easyCalls);
    }

    public function test_admin_sees_the_renewal_failure(): void
    {
        $this->actingAs(User::factory()->create());
        $service = Service::factory()->domain()->create(['domain' => 'acme.de']);

        Livewire::test(EditService::class, ['record' => $service->id])
            ->callAction('recordPayment', ['amount' => '15.00', 'paid_on' => '2026-10-08', 'method' => 'bank_transfer'])
            ->assertNotified('The payment is recorded, but the domain was not renewed at the registrar');
    }
}
