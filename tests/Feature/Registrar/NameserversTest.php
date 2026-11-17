<?php

namespace Tests\Feature\Registrar;

use App\Enums\DomainRegistrationType;
use App\Enums\ServiceStatus;
use App\Filament\Clusters\Settings\Pages\DomainSettings;
use App\Filament\Resources\Services\Pages\EditService;
use App\Models\Service;
use App\Models\User;
use App\Support\DomainName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\FakesEasy;
use Tests\TestCase;

class NameserversTest extends TestCase
{
    use FakesEasy, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeEasy();
        $this->actingAs(User::factory()->create());
    }

    private function domain(ServiceStatus $status, DomainRegistrationType $type = DomainRegistrationType::Register): Service
    {
        $service = Service::factory()->domain()->create(['domain' => 'acme.gr', 'status' => $status]);
        $service->domainDetails()->create(['registration_type' => $type, 'nameservers' => ['ns1.old.gr', 'ns2.old.gr']]);
        $this->easyDomains['acme.gr'] = ['expires' => '2027-01-02', 'ns' => ['ns1.old.gr', 'ns2.old.gr']];

        return $service->refresh();
    }

    public function test_a_nameserver_is_a_host_name(): void
    {
        foreach (['ns1.example.com', 'NS1.Example.COM.', 'a.b-c.gr', 'ns.xn--hxajbheg2az3al.gr'] as $good) {
            $this->assertTrue(DomainName::isNameserver($good), $good);
        }

        foreach (['', 'ns1', 'not a host', 'http://ns1.example.com', 'ns1..example.com', '-ns.example.com', 'ns1.example.123', '1.2.3.4', 'νς1.example.gr'] as $bad) {
            $this->assertFalse(DomainName::isNameserver($bad), $bad);
        }

        $this->assertSame(['ns1.a.gr', 'ns2.a.gr'], DomainName::nameservers([' NS1.A.gr. ', '', 'ns2.a.gr', 'ns1.a.gr']));
    }

    public function test_default_nameservers_must_be_two_to_four_host_names(): void
    {
        Livewire::test(DomainSettings::class)
            ->fillForm(['domains' => ['nameservers' => ['ns1.acme.gr', 'whatever text']]])
            ->call('save')
            ->assertHasFormErrors(['domains.nameservers']);

        Livewire::test(DomainSettings::class)
            ->fillForm(['domains' => ['nameservers' => ['ns1.acme.gr']]])
            ->call('save')
            ->assertHasFormErrors(['domains.nameservers']);

        Livewire::test(DomainSettings::class)
            ->fillForm(['domains' => ['nameservers' => ['ns1.acme.gr', 'ns2.acme.gr.']]])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(['ns1.acme.gr', 'ns2.acme.gr'], config('gaiacp.domains.nameservers'));
    }

    public function test_change_nameservers_refuses_text_that_is_not_a_host_name(): void
    {
        $service = $this->domain(ServiceStatus::Active);

        Livewire::test(EditService::class, ['record' => $service->id])
            ->callAction('setNameservers', ['nameservers' => ['ns1.new.gr', 'anything goes']])
            ->assertHasActionErrors(['nameservers']);

        $this->assertSame([], $this->easyCalls);

        Livewire::test(EditService::class, ['record' => $service->id])
            ->callAction('setNameservers', ['nameservers' => ['NS1.New.gr', 'ns2.new.gr.']])
            ->assertHasNoActionErrors();

        $this->assertSame(['ns1.new.gr', 'ns2.new.gr'], $this->easyDomains['acme.gr']['ns']);
        $this->assertSame(['ns1.new.gr', 'ns2.new.gr'], $service->refresh()->domainDetails->nameservers);
    }

    public function test_an_active_domains_nameservers_are_read_only_on_the_form_since_save_does_not_reach_the_registrar(): void
    {
        $service = $this->domain(ServiceStatus::Active);

        Livewire::test(EditService::class, ['record' => $service->id])
            ->assertFormFieldDisabled('domainDetails.nameservers')
            ->fillForm(['domainDetails' => ['nameservers' => ['ns1.other.gr', 'ns2.other.gr']]])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(['ns1.old.gr', 'ns2.old.gr'], $service->refresh()->domainDetails->nameservers);
        $this->assertSame([], $this->easyCalls);
    }

    public function test_a_pending_or_tracked_domain_keeps_its_nameservers_on_the_form_and_they_are_checked(): void
    {
        foreach ([[ServiceStatus::Pending, DomainRegistrationType::Register], [ServiceStatus::Active, DomainRegistrationType::External]] as [$status, $type]) {
            $service = $this->domain($status, $type);

            Livewire::test(EditService::class, ['record' => $service->id])
                ->assertFormFieldEnabled('domainDetails.nameservers')
                ->fillForm(['domainDetails' => ['nameservers' => ['ns1.new.gr', 'not a host']]])
                ->call('save')
                ->assertHasFormErrors(['domainDetails.nameservers']);

            Livewire::test(EditService::class, ['record' => $service->id])
                ->fillForm(['domainDetails' => ['nameservers' => ['NS1.New.gr', 'ns2.new.gr']]])
                ->call('save')
                ->assertHasNoFormErrors();

            $this->assertSame(['ns1.new.gr', 'ns2.new.gr'], $service->refresh()->domainDetails->nameservers);
            $service->domainDetails()->delete();
            $service->delete();
        }

        $this->assertSame([], $this->easyCalls);
    }

    public function test_a_wrong_nameserver_or_a_fifth_one_is_flagged_as_soon_as_it_is_added(): void
    {
        $service = $this->domain(ServiceStatus::Pending, DomainRegistrationType::Register);

        Livewire::test(EditService::class, ['record' => $service->id])
            ->fillForm(['domainDetails' => ['nameservers' => ['5464']]])
            ->assertHasFormErrors(['domainDetails.nameservers'])
            ->assertSee('"5464" is not a nameserver')
            ->fillForm(['domainDetails' => ['nameservers' => ['ns1.a.gr']]])
            ->assertHasNoFormErrors() // fixed: the message goes; two are needed, but that is only checked on save
            ->fillForm(['domainDetails' => ['nameservers' => ['ns1.a.gr', 'ns2.a.gr', 'ns3.a.gr', 'ns4.a.gr', 'ns5.a.gr']]])
            ->assertHasFormErrors(['domainDetails.nameservers']);
    }
}
