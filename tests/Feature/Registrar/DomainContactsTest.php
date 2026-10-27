<?php

namespace Tests\Feature\Registrar;

use App\Actions\DomainLifecycle;
use App\Enums\DomainRegistrationType;
use App\Enums\UserRole;
use App\Filament\Client\Resources\Domains\Pages\ViewDomain;
use App\Filament\Resources\Services\Pages\EditService;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\Service;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Plugins\EasyGr\EasyRegistrar;
use Tests\FakesEasy;
use Tests\TestCase;

/** A domain's contact details, private nameservers and registrar lock, from the client's domain page and the admin's Registrar menu. */
class DomainContactsTest extends TestCase
{
    use FakesEasy, RefreshDatabase;

    private Client $client;

    private User $user;

    private Service $domain;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeEasy();

        $this->client = Client::factory()->create([
            'first_name' => 'Maria', 'last_name' => 'Papa', 'company' => null, 'address1' => 'Stadiou 5', 'city' => 'Athens',
            'state' => 'Attica', 'postcode' => '10562', 'country' => 'GR', 'phone' => '+30 6912345678', 'email' => 'maria@new.gr',
        ]);
        $this->user = User::factory()->create(['role' => UserRole::Client, 'client_id' => $this->client->id, 'email' => $this->client->email]);
        $this->domain = $this->domainAt('maria.gr', $this->easyRegistrar->id);
        $this->easyDomains['maria.gr'] = ['expires' => '2027-03-05'];
    }

    private function domainAt(string $name, int $registrarId, DomainRegistrationType $type = DomainRegistrationType::Register): Service
    {
        $service = Service::factory()->domain()->for($this->client)->create(['domain' => $name, 'next_due_date' => '2027-03-05']);
        $service->domainDetails()->create(['registration_type' => $type, 'registrar_id' => $registrarId]);

        return $service->refresh();
    }

    private function page(?Service $domain = null): Testable
    {
        Filament::setCurrentPanel('client');
        $this->actingAs($this->user, 'client');

        return Livewire::test(ViewDomain::class, ['record' => ($domain ?? $this->domain)->getRouteKey()]);
    }

    public function test_the_client_reads_and_changes_the_contacts_at_the_registrar(): void
    {
        $this->page()
            ->assertActionVisible('domainContacts')
            ->mountAction('domainContacts')
            ->assertSchemaStateSet(['contacts.registrant.first_name' => 'Maria', 'contacts.registrant.phone' => '+30.2101234567', 'contacts.tech.first_name' => 'Tech']);
        $this->assertSame(['registrant', 'admin', 'tech'], app(DomainLifecycle::class)->contactRoles($this->domain)); // easy.gr keeps three

        $contact = fn (string $first) => ['first_name' => $first, 'last_name' => 'Papa', 'company' => 'Acme ΙΚΕ', 'address1' => 'Stadiou 5', 'address2' => '',
            'city' => 'Athens', 'state' => 'Attica', 'postcode' => '10562', 'country' => 'GR', 'phone' => '+30 6912345678', 'email' => 'maria@new.gr'];

        $this->page()
            ->callAction('domainContacts', ['contacts' => ['registrant' => $contact('Maria'), 'admin' => $contact('Nikos'), 'tech' => $contact('Eleni')]])
            ->assertHasNoFormErrors()
            ->assertNotified(__('Contact details saved'));

        $saved = $this->easyDomains['maria.gr']['saved_contacts'];
        $this->assertSame('Acme ΙΚΕ', $saved['registrant']['name']);
        $this->assertSame('+30.6912345678', $saved['admin']['voice']);
        $this->assertSame('Stadiou 5', $saved['tech']['street1']);

        $log = ActivityLog::query()->where('event', 'domain.contacts_changed')->sole();
        $this->assertSame($this->user->id, $log->user_id);
        $this->assertSame(['registrant', 'admin', 'tech'], $log->properties['roles']);
    }

    public function test_contacts_need_their_details_and_the_client_sees_a_plain_message_on_failure(): void
    {
        $this->page()
            ->callAction('domainContacts', ['contacts' => ['registrant' => ['first_name' => '', 'email' => 'not-an-email']]])
            ->assertHasFormErrors(['contacts.registrant.first_name' => 'required', 'contacts.registrant.email' => 'email']);

        $this->easyFailures[EasyRegistrar::GET_CONTACTS] = ['2303' => 'Object does not exist'];
        $this->page()
            ->mountAction('domainContacts')
            ->assertNotified(__('We could not read the contact details.'));
    }

    public function test_the_account_details_button_fills_a_contact(): void
    {
        $this->page()
            ->mountAction('domainContacts')
            ->callAction(TestAction::make('account_admin')->schemaComponent('contactActions_admin', schema: 'mountedActionSchema0'))
            ->assertSchemaStateSet(['contacts.admin.first_name' => 'Maria', 'contacts.admin.address1' => 'Stadiou 5', 'contacts.admin.email' => 'maria@new.gr'], 'mountedActionSchema0');
    }

    public function test_the_client_registers_changes_and_deletes_a_private_nameserver(): void
    {
        $this->page()
            ->assertActionVisible('childNameservers')
            ->callAction('childNameservers', ['operation' => 'save', 'host' => 'ns1', 'ip' => 'not-an-ip'])
            ->assertHasFormErrors(['ip']);

        $this->page()
            ->callAction('childNameservers', ['operation' => 'save', 'host' => 'NS1', 'ip' => '192.0.2.10'])
            ->assertHasNoFormErrors()
            ->assertNotified(__('Private nameserver saved'));
        $this->page()->callAction('childNameservers', ['operation' => 'save', 'host' => 'ns2', 'ip' => '192.0.2.11']);
        $this->page()->callAction('childNameservers', ['operation' => 'save', 'host' => 'ns1', 'ip' => '192.0.2.20']);

        $this->assertSame(['ns1.maria.gr' => '192.0.2.20', 'ns2.maria.gr' => '192.0.2.11'], $this->easyDomains['maria.gr']['hosts']);
        $this->assertSame([['host' => 'ns1.maria.gr', 'ip' => '192.0.2.20'], ['host' => 'ns2.maria.gr', 'ip' => '192.0.2.11']], $this->domain->refresh()->domainDetails->child_nameservers);

        $this->page()
            ->callAction('childNameservers', ['operation' => 'delete', 'host' => 'ns2'])
            ->assertHasNoFormErrors()
            ->assertNotified(__('Private nameserver deleted'));

        $this->assertSame(['ns1.maria.gr' => '192.0.2.20'], $this->easyDomains['maria.gr']['hosts']);
        $this->assertSame([['host' => 'ns1.maria.gr', 'ip' => '192.0.2.20']], $this->domain->refresh()->domainDetails->child_nameservers);
        $this->assertSame(3, ActivityLog::query()->where('event', 'domain.child_nameserver_saved')->count());
    }

    public function test_switches_registrars_and_tracked_domains_decide_what_the_client_sees(): void
    {
        // easy.gr has no registrar lock
        $this->page()->assertActionHidden('registrarLock');

        config(['gaiacp.client_area.domain_contacts' => false, 'gaiacp.client_area.domain_child_nameservers' => false]);
        $this->page()->assertActionHidden('domainContacts')->assertActionHidden('childNameservers');

        config(['gaiacp.client_area.domain_contacts' => true, 'gaiacp.client_area.domain_child_nameservers' => true]);
        $tracked = $this->domainAt('elsewhere.gr', $this->easyRegistrar->id, DomainRegistrationType::External);
        $this->page($tracked)->assertActionHidden('domainContacts')->assertActionHidden('childNameservers');
    }

    public function test_the_admin_sees_the_registrars_answer_when_it_fails(): void
    {
        $this->easyFailures[EasyRegistrar::SAVE_CHILD_NAMESERVER] = ['ns' => 'Host already exists'];
        $this->actingAs(User::factory()->create());

        Livewire::test(EditService::class, ['record' => $this->domain->id])
            ->callAction('childNameservers', ['operation' => 'save', 'host' => 'ns1', 'ip' => '192.0.2.10'])
            ->assertNotified();

        $this->assertNull($this->domain->refresh()->domainDetails->child_nameservers);
        $this->assertStringContainsString('Host already exists', ActivityLog::query()->where('event', 'provisioning.failed')->value('description'));
    }
}
