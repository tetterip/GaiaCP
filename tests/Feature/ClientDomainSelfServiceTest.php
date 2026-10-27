<?php

namespace Tests\Feature;

use App\Enums\DomainRegistrationType;
use App\Enums\ServiceStatus;
use App\Enums\UserRole;
use App\Filament\Client\Resources\Domains\Pages\ViewDomain;
use App\Filament\Clusters\Settings\Pages\ClientAreaSettings;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\Service;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Plugins\EasyGr\EasyRegistrar;
use Tests\FakesEasy;
use Tests\TestCase;

/** Client area step 5: a client changes nameservers, reads the EPP code and turns auto renew on or off on their own domain. */
class ClientDomainSelfServiceTest extends TestCase
{
    use FakesEasy, RefreshDatabase;

    private Client $client;

    private User $user;

    private Service $domain;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeEasy();

        $this->client = Client::factory()->create();
        $this->user = User::factory()->create(['role' => UserRole::Client, 'client_id' => $this->client->id, 'email' => $this->client->email]);

        $this->domain = Service::factory()->domain()->for($this->client)->create(['domain' => 'maria.gr', 'next_due_date' => '2027-03-05']);
        $this->domain->domainDetails()->create([
            'registration_type' => DomainRegistrationType::Register,
            'registrar_id' => $this->easyRegistrar->id,
            'nameservers' => ['ns1.old.gr', 'ns2.old.gr'],
        ]);
        $this->easyDomains['maria.gr'] = ['expires' => '2027-03-05', 'ns' => ['ns1.old.gr', 'ns2.old.gr'], 'epp' => 'Xy-9!'];
    }

    private function page(?Service $domain = null): Testable
    {
        Filament::setCurrentPanel('client');
        $this->actingAs($this->user, 'client');

        return Livewire::test(ViewDomain::class, ['record' => ($domain ?? $this->domain)->getRouteKey()]);
    }

    public function test_the_client_changes_the_nameservers_at_the_registrar(): void
    {
        $this->page()
            ->assertActionVisible('changeNameservers')
            ->mountAction('changeNameservers')
            ->assertSchemaStateSet(['ns1' => 'ns1.old.gr', 'ns2' => 'ns2.old.gr', 'ns3' => null])
            ->callMountedAction()
            ->assertHasNoFormErrors();

        $this->page()
            ->callAction('changeNameservers', ['ns1' => 'NS1.New-Host.com', 'ns2' => 'ns2.new-host.com.', 'ns3' => '', 'ns4' => ''])
            ->assertHasNoFormErrors()
            ->assertNotified(__('Nameservers changed'));

        $this->assertSame(['ns1.new-host.com', 'ns2.new-host.com'], $this->easyDomains['maria.gr']['ns']);
        $this->assertSame(['ns1.new-host.com', 'ns2.new-host.com'], $this->domain->refresh()->domainDetails->nameservers);

        $log = ActivityLog::query()->where('event', 'domain.nameservers_changed')->latest('id')->first();
        $this->assertSame($this->user->id, $log->user_id);
        $this->assertSame($this->domain->id, $log->service_id);
    }

    public function test_nameservers_need_two_valid_names(): void
    {
        $this->page()
            ->callAction('changeNameservers', ['ns1' => 'ns1.new.gr', 'ns2' => 'not a host', 'ns3' => 'ns1.new.gr'])
            ->assertHasFormErrors(['ns2', 'ns3']);

        $this->page()
            ->callAction('changeNameservers', ['ns1' => 'ns1.new.gr', 'ns2' => ''])
            ->assertHasFormErrors(['ns2' => 'required']);

        $this->assertNotContains(EasyRegistrar::SAVE_NAMESERVERS, $this->easyActions());
    }

    public function test_a_registrar_failure_shows_a_plain_message_and_keeps_the_details_in_the_log(): void
    {
        $this->easyFailures[EasyRegistrar::SAVE_NAMESERVERS] = ['2306' => 'Host ns1.bad.gr does not exist'];

        $this->page()
            ->callAction('changeNameservers', ['ns1' => 'ns1.bad.gr', 'ns2' => 'ns2.bad.gr'])
            ->assertNotified(__('We could not change the nameservers.'))
            ->assertDontSee('2306');

        $this->assertSame(['ns1.old.gr', 'ns2.old.gr'], $this->domain->refresh()->domainDetails->nameservers);
        $this->assertTrue(ActivityLog::query()->where('event', 'provisioning.failed')->where('description', 'like', '%ns1.bad.gr%')->exists());
    }

    public function test_the_client_gets_the_epp_code(): void
    {
        $this->page()
            ->callAction('getEppCode')
            ->assertNotified(__('EPP code of :domain', ['domain' => 'maria.gr']));

        $log = ActivityLog::query()->where('event', 'domain.epp_requested')->sole();
        $this->assertSame($this->user->id, $log->user_id);
        $this->assertStringNotContainsString('Xy-9!', $log->description);
    }

    public function test_the_client_turns_auto_renew_off_and_on(): void
    {
        $this->page()->callAction('toggleAutoRenew')->assertNotified(__('Auto renew is off'));

        $this->domain->refresh();
        $this->assertTrue($this->domain->auto_terminate);
        $this->assertSame('Auto renew turned off by the client', $this->domain->auto_terminate_reason);
        $this->assertTrue(ActivityLog::query()->where('event', 'domain.auto_renew_changed')->where('user_id', $this->user->id)->exists());

        $this->page()->callAction('toggleAutoRenew')->assertNotified(__('Auto renew is on'));

        $this->domain->refresh();
        $this->assertFalse($this->domain->auto_terminate);
        $this->assertNull($this->domain->auto_terminate_reason);
    }

    public function test_domains_registered_elsewhere_or_not_active_offer_no_registrar_actions(): void
    {
        $external = Service::factory()->domain()->for($this->client)->create(['domain' => 'elsewhere.gr']);
        $external->domainDetails()->create(['registration_type' => DomainRegistrationType::External]);

        $this->page($external)
            ->assertActionHidden('changeNameservers')
            ->assertActionHidden('getEppCode')
            ->assertActionVisible('toggleAutoRenew');

        $this->domain->update(['status' => ServiceStatus::Suspended]);

        $this->page()
            ->assertActionHidden('changeNameservers')
            ->assertActionHidden('getEppCode')
            ->assertActionVisible('toggleAutoRenew');

        $this->domain->update(['status' => ServiceStatus::Pending]);

        $this->page()->assertActionHidden('toggleAutoRenew');
    }

    public function test_each_action_has_a_switch_under_settings(): void
    {
        $admin = User::factory()->create();
        Filament::setCurrentPanel('admin');
        $this->actingAs($admin, 'web');

        Livewire::test(ClientAreaSettings::class)
            ->assertFormSet(['client_area.domain_nameservers' => true, 'client_area.domain_epp' => true, 'client_area.domain_auto_renew' => true])
            ->fillForm(['client_area.domain_nameservers' => false, 'client_area.domain_epp' => false, 'client_area.domain_auto_renew' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertFalse(config('gaiacp.client_area.domain_nameservers'));

        $this->page()
            ->assertActionHidden('changeNameservers')
            ->assertActionHidden('getEppCode')
            ->assertActionHidden('toggleAutoRenew');
    }
}
