<?php

namespace Tests\Feature\Registrar;

use App\Enums\BillingCycle;
use App\Enums\DomainRegistrationType;
use App\Enums\ServiceStatus;
use App\Filament\Resources\Services\Pages\EditService;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\Registrar;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\FakesEasy;
use Tests\TestCase;

/** A domain set up at Email Notifications, then moved to easy.gr on its page. */
class SwitchRegistrarTest extends TestCase
{
    use FakesEasy, RefreshDatabase;

    private function domainAtEmailNotifications(): Service
    {
        $this->travelTo('2026-10-19 10:00');
        $this->fakeEasy();

        $service = Service::factory()->domain()->for(Client::factory())->create([
            'domain' => 'acme.gr', 'status' => ServiceStatus::Active, 'billing_cycle' => BillingCycle::Biennially,
            'start_date' => '2026-10-19', 'next_due_date' => '2028-10-19',
        ]);
        $service->domainDetails()->create([
            'registration_type' => DomainRegistrationType::Register,
            'registrar_id' => Registrar::query()->where('module', 'email')->sole()->id,
        ]);
        $this->actingAs(User::factory()->create());

        return $service;
    }

    public function test_once_on_easy_gr_it_syncs(): void
    {
        $this->easyDomains['acme.gr'] = ['expires' => '2028-10-19', 'ns' => ['ns1.host.gr', 'ns2.host.gr']];
        $service = $this->domainAtEmailNotifications();

        Livewire::test(EditService::class, ['record' => $service->id])
            ->assertActionHidden('syncDomain')
            ->fillForm(['domainDetails.registrar_id' => $this->easyRegistrar->id])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertActionVisible('syncDomain')
            ->callAction('syncDomain')
            ->assertNotified(__('Synced'));

        Livewire::test(EditService::class, ['record' => $service->id])
            ->assertActionVisible('syncDomain');

        $this->assertSame($this->easyRegistrar->id, $service->refresh()->domainDetails->registrar_id);
        $this->assertSame('2028-10-19', $service->domainDetails->expires_at->toDateString());
    }

    public function test_a_domain_easy_gr_does_not_hold_says_so(): void
    {
        $service = $this->domainAtEmailNotifications();

        Livewire::test(EditService::class, ['record' => $service->id])
            ->fillForm(['domainDetails.registrar_id' => $this->easyRegistrar->id])
            ->call('save')
            ->callAction('syncDomain')
            ->assertNotified(__('Failed'));

        $this->assertTrue(ActivityLog::query()->where('event', 'provisioning.failed')
            ->where('description', 'like', '%acme.gr is not in this easy.gr account%')->exists());
    }

    public function test_past_pending_the_choices_say_what_they_mean_and_track_only_hides_the_registrar(): void
    {
        $service = $this->domainAtEmailNotifications();

        Livewire::test(EditService::class, ['record' => $service->id])
            ->assertSee(__('At the registrar (synced and renewed there)'))
            ->assertFormFieldVisible('domainDetails.registrar_id')
            ->fillForm(['domainDetails.registration_type' => DomainRegistrationType::External->value])
            ->assertFormFieldHidden('domainDetails.registrar_id');
    }
}
