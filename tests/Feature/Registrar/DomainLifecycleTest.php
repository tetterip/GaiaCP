<?php

namespace Tests\Feature\Registrar;

use App\Actions\DomainLifecycle;
use App\Actions\RecordPayment;
use App\Enums\DomainRegistrationType;
use App\Enums\ServiceStatus;
use App\Filament\Resources\Services\Pages\EditService;
use App\Filament\Resources\Services\Pages\ListServices;
use App\Models\ActivityLog;
use App\Models\Registrar;
use App\Models\Service;
use App\Models\User;
use App\Provisioning\ProvisioningException;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Plugins\EasyGr\EasyRegistrar;
use Tests\FakesEasy;
use Tests\TestCase;

class DomainLifecycleTest extends TestCase
{
    use FakesEasy, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-09 10:00');
        $this->fakeEasy();
        config(['gaiacp.domains.nameservers' => ['ns1.host.gr', 'ns2.host.gr']]);
    }

    private function pendingDomain(DomainRegistrationType $type = DomainRegistrationType::Register, array $details = []): Service
    {
        $service = Service::factory()->domain()->create([
            'domain' => 'acme.gr',
            'status' => ServiceStatus::Pending,
            'start_date' => '2026-10-09',
            'next_due_date' => '2026-10-09',
        ]);
        $service->domainDetails()->create(['registration_type' => $type, ...$details]);

        return $service->refresh();
    }

    public function test_registering_a_pending_domain(): void
    {
        $service = $this->pendingDomain();

        app(DomainLifecycle::class)->activate($service);

        $this->assertSame([EasyRegistrar::SYNC, EasyRegistrar::REGISTER, EasyRegistrar::SYNC, EasyRegistrar::GET_NAMESERVERS], $this->easyActions());
        $this->assertSame(['domain' => 'acme.gr', 'years' => '1', 'ns1' => 'ns1.host.gr', 'ns2' => 'ns2.host.gr', 'ns3' => '', 'ns4' => ''], array_intersect_key($this->easyCalls[1][1], array_flip(['domain', 'years', 'ns1', 'ns2', 'ns3', 'ns4'])));
        $this->assertSame(ServiceStatus::Active, $service->refresh()->status);
        $this->assertSame('2027-10-09', $service->domainDetails->expires_at->toDateString());
        $this->assertSame($this->easyRegistrar->id, $service->domainDetails->registrar_id);
        // Read back at once, without waiting for Sync.
        $this->assertSame(['ns1.host.gr', 'ns2.host.gr'], $service->domainDetails->nameservers);
        $this->assertNotNull($service->domainDetails->last_synced_at);
        $this->assertTrue(ActivityLog::where('event', 'domain.registered')->exists());
    }

    public function test_a_failed_nameserver_read_after_registering_still_activates(): void
    {
        $service = $this->pendingDomain();
        $this->easyFailures[EasyRegistrar::GET_NAMESERVERS] = ['2400' => 'Command failed'];

        app(DomainLifecycle::class)->activate($service);

        $this->assertSame(ServiceStatus::Active, $service->refresh()->status);
        $this->assertSame('2027-10-09', $service->domainDetails->expires_at->toDateString());
        $this->assertNull($service->domainDetails->nameservers);
    }

    public function test_the_domains_own_nameservers_win_over_the_defaults(): void
    {
        $service = $this->pendingDomain(details: ['nameservers' => ['NS1.Other.gr', 'ns2.other.gr']]);

        app(DomainLifecycle::class)->activate($service);

        $this->assertSame(['ns1.other.gr', 'ns2.other.gr'], $this->easyDomains['acme.gr']['ns']);
    }

    public function test_a_retry_after_a_timeout_does_not_register_twice(): void
    {
        $service = $this->pendingDomain();
        $this->easyDomains['acme.gr'] = ['expires' => '2027-10-09'];

        app(DomainLifecycle::class)->activate($service);

        $this->assertNotContains(EasyRegistrar::REGISTER, $this->easyActions());
        $this->assertSame(ServiceStatus::Active, $service->refresh()->status);
    }

    public function test_a_failed_registration_leaves_the_domain_pending(): void
    {
        $service = $this->pendingDomain();
        $this->easyFailures[EasyRegistrar::REGISTER] = ['2302' => 'Object exists'];

        try {
            app(DomainLifecycle::class)->activate($service);
            $this->fail('Expected a ProvisioningException');
        } catch (ProvisioningException) {
        }

        $this->assertSame(ServiceStatus::Pending, $service->refresh()->status);
        $this->assertTrue(ActivityLog::where('event', 'provisioning.failed')->exists());
    }

    public function test_registration_needs_two_nameservers(): void
    {
        config(['gaiacp.domains.nameservers' => []]);
        $service = $this->pendingDomain();

        $this->expectExceptionMessage('at least two nameservers');

        app(DomainLifecycle::class)->activate($service);
    }

    public function test_transfer_sends_the_epp_code(): void
    {
        $service = $this->pendingDomain(DomainRegistrationType::Transfer, ['epp_code' => 'Secret-123']);

        app(DomainLifecycle::class)->activate($service);

        $this->assertSame([EasyRegistrar::SYNC, EasyRegistrar::TRANSFER], $this->easyActions());
        $this->assertSame('Secret-123', $this->easyCalls[1][1]['pw']);
        $this->assertSame(ServiceStatus::Active, $service->refresh()->status);
        $this->assertTrue(ActivityLog::where('event', 'domain.transfer_requested')->exists());
    }

    public function test_transfer_without_an_epp_code_fails(): void
    {
        $service = $this->pendingDomain(DomainRegistrationType::Transfer);

        $this->expectExceptionMessage('EPP code');

        app(DomainLifecycle::class)->activate($service);
    }

    public function test_a_domain_registered_elsewhere_is_only_marked_active(): void
    {
        $service = $this->pendingDomain(DomainRegistrationType::External);

        app(DomainLifecycle::class)->activate($service);

        $this->assertSame([], $this->easyCalls);
        $this->assertSame(ServiceStatus::Active, $service->refresh()->status);
    }

    public function test_the_chosen_registrar_is_used_over_the_tld_match(): void
    {
        $other = Registrar::factory()->create(['name' => 'Second account', 'is_default' => false]);
        $service = $this->pendingDomain(details: ['registrar_id' => $other->id]);

        app(DomainLifecycle::class)->activate($service);

        $this->assertSame($other->id, $service->refresh()->domainDetails->registrar_id);
        $this->assertStringContainsString('Second account', ActivityLog::where('event', 'domain.registered')->value('description'));
    }

    public function test_sync_reads_expiry_and_nameservers_and_leaves_the_due_date(): void
    {
        $service = Service::factory()->domain()->create(['domain' => 'acme.gr', 'next_due_date' => '2026-11-01']);
        $this->easyDomains['acme.gr'] = ['expires' => '2026-11-05', 'ns' => ['ns1.x.gr', 'ns2.x.gr']];

        app(DomainLifecycle::class)->sync($service);

        $details = $service->refresh()->domainDetails;
        $this->assertSame('2026-11-05', $details->expires_at->toDateString());
        $this->assertSame(['ns1.x.gr', 'ns2.x.gr'], $details->nameservers);
        $this->assertSame('2026-11-01', $service->next_due_date->toDateString());
    }

    public function test_the_first_payment_after_registering_does_not_renew_again(): void
    {
        $service = $this->pendingDomain();
        app(DomainLifecycle::class)->activate($service);
        $this->easyCalls = [];

        app(RecordPayment::class)->handle($service->refresh(), 1500, CarbonImmutable::parse('2026-10-09'), 'bank_transfer');

        $this->assertSame([], $this->easyCalls);
        $this->assertSame('2027-10-09', $service->refresh()->next_due_date->toDateString());
    }

    public function test_the_next_years_payment_renews(): void
    {
        $service = Service::factory()->domain()->create(['domain' => 'acme.gr', 'start_date' => '2025-11-01', 'next_due_date' => '2026-11-01']);
        $service->domainDetails()->create(['expires_at' => '2026-11-01']);
        $this->easyDomains['acme.gr'] = ['expires' => '2026-11-01'];

        app(RecordPayment::class)->handle($service->refresh(), 1500, CarbonImmutable::parse('2026-10-09'), 'bank_transfer');

        $this->assertSame([EasyRegistrar::RENEW], $this->easyActions());
        $this->assertSame('2027-11-01', $service->refresh()->domainDetails->expires_at->toDateString());
    }

    public function test_the_registrar_menu_registers_from_the_service_page(): void
    {
        $this->actingAs(User::factory()->create());
        $service = $this->pendingDomain();

        Livewire::test(EditService::class, ['record' => $service->id])
            ->callAction('activateDomain')
            ->assertNotified('Done');

        $this->assertSame(ServiceStatus::Active, $service->refresh()->status);
    }

    public function test_the_service_form_saves_the_domain_details(): void
    {
        $this->actingAs(User::factory()->create());
        $service = Service::factory()->domain()->create(['domain' => 'acme.gr', 'status' => ServiceStatus::Pending]);

        Livewire::test(EditService::class, ['record' => $service->id])
            ->fillForm(['domainDetails.registration_type' => DomainRegistrationType::Transfer->value, 'domainDetails.epp_code' => 'Code-9'])
            ->call('save')
            ->assertHasNoFormErrors();

        $details = $service->refresh()->domainDetails;
        $this->assertSame(DomainRegistrationType::Transfer, $details->registration_type);
        $this->assertSame('Code-9', $details->epp_code);
    }

    public function test_the_domains_tab_lists_only_domains(): void
    {
        $this->actingAs(User::factory()->create());
        $domain = Service::factory()->domain()->create(['domain' => 'acme.gr']);
        $hosting = Service::factory()->create();

        Livewire::withQueryParams(['tab' => 'domains'])
            ->test(ListServices::class)
            ->assertCanSeeTableRecords([$domain])
            ->assertCanNotSeeTableRecords([$hosting]);

        $this->get('/admin/services?tab=domains')->assertOk()->assertSee('Domains')->assertSee('Other services');
    }

    public function test_daily_sync_command(): void
    {
        $service = Service::factory()->domain()->create(['domain' => 'acme.gr']);
        $this->easyDomains['acme.gr'] = ['expires' => '2027-01-02'];

        $this->artisan('gaiacp:sync-domains')->assertSuccessful();

        $this->assertSame('2027-01-02', $service->refresh()->domainDetails->expires_at->toDateString());
    }

    public function test_a_tracked_domain_is_never_renewed_or_synced(): void
    {
        $service = Service::factory()->domain()->create(['domain' => 'acme.gr']);
        $service->domainDetails()->create(['registration_type' => DomainRegistrationType::External]);

        app(RecordPayment::class)->handle($service->refresh(), 1500, CarbonImmutable::parse('2026-10-09'), 'bank_transfer');
        $this->artisan('gaiacp:sync-domains')->assertSuccessful();

        $this->assertSame([], $this->easyCalls);
    }

    public function test_at_most_four_nameservers(): void
    {
        $service = Service::factory()->domain()->create(['domain' => 'acme.gr']);
        $this->easyDomains['acme.gr'] = ['expires' => '2027-01-02'];

        try {
            app(DomainLifecycle::class)->setNameservers($service, ['ns1.a.gr', 'ns2.a.gr', 'ns3.a.gr', 'ns4.a.gr', 'ns5.a.gr']);
            $this->fail('Expected a ProvisioningException');
        } catch (ProvisioningException $e) {
            $this->assertStringContainsString('at most 4', $e->getMessage());
        }

        $this->actingAs(User::factory()->create());
        Livewire::test(EditService::class, ['record' => $service->id])
            ->callAction('setNameservers', ['nameservers' => ['ns1.a.gr', 'ns2.a.gr', 'ns3.a.gr', 'ns4.a.gr', 'ns5.a.gr']])
            ->assertHasActionErrors(['nameservers' => 'max']);

        app(DomainLifecycle::class)->setNameservers($service, ['ns1.a.gr', 'ns2.a.gr', 'ns3.a.gr', 'ns4.a.gr']);
        $this->assertSame(['ns1.a.gr', 'ns2.a.gr', 'ns3.a.gr', 'ns4.a.gr'], $this->easyDomains['acme.gr']['ns']);
    }
}
