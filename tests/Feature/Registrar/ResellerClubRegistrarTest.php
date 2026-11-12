<?php

namespace Tests\Feature\Registrar;

use App\Actions\DomainLifecycle;
use App\Enums\DomainRegistrationType;
use App\Enums\ServiceStatus;
use App\Filament\Resources\Registrars\Pages\CreateRegistrar;
use App\Filament\Resources\Services\Pages\EditService;
use App\Models\Client;
use App\Models\ModuleLog;
use App\Models\Registrar;
use App\Models\Service;
use App\Models\User;
use App\Provisioning\DomainRegistrar;
use App\Provisioning\ProvisioningException;
use App\Provisioning\RegistrarFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Plugins\ResellerClub\ResellerClubClient;
use Plugins\ResellerClub\ResellerClubRegistrar;
use Tests\FakesResellerClub;
use Tests\TestCase;

class ResellerClubRegistrarTest extends TestCase
{
    use FakesResellerClub, RefreshDatabase;

    /** @return ResellerClubRegistrar */
    private function registrar(array $credentials = []): DomainRegistrar
    {
        return app(RegistrarFactory::class)->make('resellerclub', [...['reseller_id' => '123456', 'api_key' => 'secret'], ...$credentials]);
    }

    private function holds(string $domain = 'acme.com'): void
    {
        $this->resellerclubContacts['77'] = ['name' => 'John Smith', 'company' => 'Acme Inc', 'email' => 'john@acme.com', 'address-line-1' => '1 Main St', 'city' => 'Boston',
            'state' => 'MA', 'zipcode' => '02101', 'country' => 'US', 'phone-cc' => '1', 'phone' => '6175551234'];
        $this->resellerclubDomains[$domain] = ['orderid' => 4321, 'customerid' => '55', 'endtime' => (string) strtotime('2027-03-15 12:00:00 UTC'),
            'ns' => ['NS1.Host.gr', 'ns2.host.gr'], 'domsecret' => 'Rc#Code1', 'orderstatus' => ['transferlock'], 'cns' => [],
            'registrantcontactid' => '77', 'admincontactid' => '77', 'techcontactid' => '77', 'billingcontactid' => '77'];
    }

    public function test_reads_the_domain_once_with_the_login(): void
    {
        $this->fakeResellerClub();
        $this->holds();
        $registrar = $this->registrar();

        $this->assertSame('2027-03-15', $registrar->expiryDate('Acme.com')->toDateString());
        $this->assertSame(['ns1.host.gr', 'ns2.host.gr'], $registrar->nameservers('acme.com'));
        $this->assertTrue($registrar->locked('acme.com'));
        $this->assertSame('Rc#Code1', $registrar->eppCode('acme.com'));

        $this->assertSame([['domains/details-by-name', ['domain-name' => 'acme.com', 'options' => 'All']]], $this->resellerclubCalls);
        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), ResellerClubClient::LIVE_URL.'domains/details-by-name.json?auth-userid=123456&api-key=secret'));
    }

    public function test_test_mode_uses_the_test_url(): void
    {
        $this->fakeResellerClub();
        $this->holds();

        $this->registrar(['test_mode' => true])->expiryDate('acme.com');

        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), ResellerClubClient::TEST_URL));
    }

    public function test_lists_repeat_the_parameter_name(): void
    {
        $this->assertSame('order-id=1&ns=a.ns.gr&ns=b.ns.gr&protect-privacy=false', ResellerClubClient::encode(['order-id' => 1, 'ns' => ['a.ns.gr', 'b.ns.gr'], 'skip' => null, 'protect-privacy' => false]));
    }

    public function test_register_signs_the_client_up_once_and_makes_a_contact(): void
    {
        $this->fakeResellerClub();
        $client = Client::factory()->create([
            'first_name' => 'Νίκος', 'last_name' => 'Παπαδόπουλος', 'company' => null, 'email' => 'nikos@example.gr', 'phone' => '210 123 4567',
            'address1' => 'Ερμού 1', 'address2' => null, 'city' => 'Αθήνα', 'state' => 'Αττική', 'postcode' => '10563', 'country' => 'gr',
        ]);

        $this->registrar()->register('acme.com', 2, $client, ['ns1.host.gr', 'ns2.host.gr']);
        $this->registrar()->register('acme.net', 1, $client, []);

        $this->assertSame(['customers/details', 'customers/v2/signup', 'contacts/add', 'domains/register', 'customers/details', 'contacts/add', 'domains/register'], $this->resellerclubCallNames());
        $contact = $this->resellerclubCalls[2][1];
        $this->assertSame(['Νίκος Παπαδόπουλος', 'N/A', 'nikos@example.gr', 'Ερμού 1', 'Αθήνα', 'GR', '10563', '30', '2101234567', '500', 'Contact'],
            [$contact['name'], $contact['company'], $contact['email'], $contact['address-line-1'], $contact['city'], $contact['country'], $contact['zipcode'], $contact['phone-cc'], $contact['phone'], $contact['customer-id'], $contact['type']]);

        $register = $this->resellerclubCalls[3][1];
        $this->assertSame(['acme.com', '2', ['ns1.host.gr', 'ns2.host.gr'], '500', '900', '900', 'NoInvoice'],
            [$register['domain-name'], $register['years'], $register['ns'], $register['customer-id'], $register['reg-contact-id'], $register['billing-contact-id'], $register['invoice-option']]);
        $this->assertArrayNotHasKey('ns', $this->resellerclubCalls[6][1]);
    }

    public function test_transfer_keeps_the_epp_code_and_key_out_of_the_module_log(): void
    {
        config(['gaiacp.debug.module_log' => true]);
        $this->fakeResellerClub();
        $this->resellerclubCustomers['nikos@example.gr'] = '55';

        $this->registrar()->transfer('acme.com', 'Auth&Xq9z', Client::factory()->create(['email' => 'nikos@example.gr']));

        $this->assertSame(['customers/details', 'contacts/add', 'domains/transfer'], $this->resellerclubCallNames());
        $this->assertSame(['Auth&Xq9z', '55'], [$this->resellerclubCalls[2][1]['auth-code'], $this->resellerclubCalls[2][1]['customer-id']]);

        $text = ModuleLog::query()->where('module', 'resellerclub')->get()->map(fn ($log) => $log->url.$log->request.$log->response)->implode('');
        $this->assertStringNotContainsString('Xq9z', $text);
        $this->assertStringNotContainsString('secret', $text);
    }

    public function test_renews_with_the_current_expiry_and_reads_the_new_one(): void
    {
        $this->fakeResellerClub();
        $this->holds();
        $registrar = $this->registrar();

        $registrar->renew('acme.com', 2);

        $this->assertSame(['4321', '2', (string) strtotime('2027-03-15 12:00:00 UTC'), 'NoInvoice'], array_values($this->resellerclubCalls[1][1]));
        $this->assertSame('2029-03-15', $registrar->expiryDate('acme.com')->toDateString());
    }

    public function test_saves_nameservers_and_the_lock(): void
    {
        $this->fakeResellerClub();
        $this->holds();
        $registrar = $this->registrar();

        $registrar->setNameservers('acme.com', ['a.ns.gr', 'b.ns.gr']);
        $registrar->setLocked('acme.com', false);

        $this->assertSame(['domains/modify-ns', ['order-id' => '4321', 'ns' => ['a.ns.gr', 'b.ns.gr']]], $this->resellerclubCalls[1]);
        $this->assertSame(['a.ns.gr', 'b.ns.gr'], $registrar->nameservers('acme.com'));
        $this->assertFalse($registrar->locked('acme.com'));
    }

    public function test_availability(): void
    {
        $this->fakeResellerClub();
        $this->holds('taken.com');

        $this->assertTrue($this->registrar()->available('free.com'));
        $this->assertFalse($this->registrar()->available('taken.com'));
        $this->assertSame(['domain-name' => 'free', 'tlds' => 'com'], $this->resellerclubCalls[0][1]);
    }

    public function test_errors_show_the_message(): void
    {
        $this->fakeResellerClub();

        $this->expectExceptionMessage("ResellerClub: Website doesn't exist for acme.com");
        $this->registrar()->expiryDate('acme.com');
    }

    public function test_missing_credentials_never_call_the_api(): void
    {
        $registrar = $this->registrar(['api_key' => '']);

        $this->assertFalse($registrar->configured());

        try {
            $registrar->expiryDate('acme.com');
            $this->fail('Expected an exception');
        } catch (ProvisioningException $e) {
            $this->assertStringContainsString('Settings → Registrars', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_registering_through_the_domain_lifecycle(): void
    {
        $this->travelTo('2026-10-09 10:00');
        $this->fakeResellerClub();
        config(['gaiacp.domains.nameservers' => ['ns1.host.gr', 'ns2.host.gr']]);
        $service = Service::factory()->domain()->create(['domain' => 'acme.com', 'status' => ServiceStatus::Pending, 'start_date' => '2026-10-09', 'next_due_date' => '2026-10-09']);
        $service->domainDetails()->create(['registration_type' => DomainRegistrationType::Register, 'registrar_id' => $this->resellerclubRegistrar->id]);

        app(DomainLifecycle::class)->activate($service->refresh());

        $this->assertSame(ServiceStatus::Active, $service->refresh()->status);
        $this->assertSame('2027-10-09', $service->domainDetails->expires_at->toDateString());
    }

    public function test_create_a_resellerclub_account(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(CreateRegistrar::class)
            ->fillForm(['module' => 'resellerclub'])
            ->fillForm(['name' => 'ResellerClub', 'credentials' => ['reseller_id' => '123456', 'api_key' => 'k', 'test_mode' => true]])
            ->call('create')
            ->assertHasNoFormErrors();

        $registrar = Registrar::query()->where('module', 'resellerclub')->sole();
        $this->assertSame(['reseller_id' => '123456', 'api_key' => 'k', 'test_mode' => true], $registrar->credentials);
        $this->assertInstanceOf(ResellerClubRegistrar::class, app(RegistrarFactory::class)->forRegistrar($registrar));
    }

    public function test_the_admin_changes_contacts_and_private_nameservers(): void
    {
        $this->fakeResellerClub();
        $service = Service::factory()->domain()->create(['domain' => 'acme.com', 'next_due_date' => '2027-03-15']);
        $service->domainDetails()->create(['registration_type' => DomainRegistrationType::Register, 'registrar_id' => $this->resellerclubRegistrar->id]);
        $this->holds();
        $this->actingAs(User::factory()->create());

        Livewire::test(EditService::class, ['record' => $service->id])
            ->mountAction('domainContacts')
            ->assertSchemaStateSet(['contacts.registrant.first_name' => 'John', 'contacts.registrant.last_name' => 'Smith', 'contacts.billing.company' => 'Acme Inc'])
            ->fillForm(['contacts.tech.first_name' => 'Tom'])
            ->callMountedAction()
            ->assertHasNoFormErrors();

        $d = $this->resellerclubDomains['acme.com'];
        $this->assertSame($d['registrantcontactid'], $d['admincontactid']);
        $this->assertNotSame($d['registrantcontactid'], $d['techcontactid']);
        $this->assertSame('Tom Smith', $this->resellerclubContacts[$d['techcontactid']]['name']);
        $this->assertSame('55', $this->resellerclubContacts[$d['techcontactid']]['customer-id']);

        Livewire::test(EditService::class, ['record' => $service->id])
            ->callAction('childNameservers', ['operation' => 'save', 'host' => 'ns1', 'ip' => '192.0.2.10'])
            ->callAction('childNameservers', ['operation' => 'save', 'host' => 'ns1', 'ip' => '192.0.2.20']);
        $this->assertSame(['ns1.acme.com' => ['192.0.2.20']], $this->resellerclubDomains['acme.com']['cns']);
        $this->assertContains('domains/modify-cns-ip', $this->resellerclubCallNames());

        $this->registrar()->deleteChildNameserver('acme.com', 'ns1.acme.com');
        $this->assertSame([], $this->resellerclubDomains['acme.com']['cns']);
    }
}
