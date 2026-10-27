<?php

namespace Tests\Feature\Registrar;

use App\Actions\DomainLifecycle;
use App\Enums\DomainRegistrationType;
use App\Enums\ServiceStatus;
use App\Enums\UserRole;
use App\Filament\Client\Resources\Domains\Pages\ViewDomain;
use App\Filament\Resources\Registrars\Pages\CreateRegistrar;
use App\Filament\Resources\Services\Pages\EditService;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\ModuleLog;
use App\Models\Registrar;
use App\Models\Service;
use App\Models\User;
use App\Provisioning\DomainRegistrar;
use App\Provisioning\ProvisioningException;
use App\Provisioning\RegistrarFactory;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Plugins\Enom\EnomClient;
use Plugins\Enom\EnomRegistrar;
use Tests\FakesEnom;
use Tests\TestCase;

class EnomRegistrarTest extends TestCase
{
    use FakesEnom, RefreshDatabase;

    private function registrar(array $credentials = []): DomainRegistrar
    {
        return app(RegistrarFactory::class)->make('enom', [...['uid' => 'reseller', 'password' => 'secret'], ...$credentials]);
    }

    private function activeDomain(string $domain = 'acme.com'): Service
    {
        $service = Service::factory()->domain()->create(['domain' => $domain, 'next_due_date' => '2027-03-15']);
        $service->domainDetails()->create(['registration_type' => DomainRegistrationType::Register, 'registrar_id' => $this->enomRegistrar->id]);
        $this->enomDomains[$domain] = ['expires' => '2027-03-15', 'ns' => ['ns1.host.gr', 'ns2.host.gr']];

        return $service->refresh();
    }

    public function test_posts_the_login_and_command_as_a_form(): void
    {
        $this->fakeEnom();
        $this->enomDomains['acme.com'] = ['expires' => '2027-03-15'];

        $this->assertSame('2027-03-15', $this->registrar()->expiryDate('acme.com')->toDateString());

        Http::assertSent(fn (Request $request) => $request->url() === EnomClient::LIVE_URL
            && $request->method() === 'POST'
            && $request->isForm()
            && $request['uid'] === 'reseller'
            && $request['pw'] === 'secret'
            && $request['command'] === 'GetDomainInfo'
            && $request['responsetype'] === 'xml'
            && $request['SLD'] === 'acme'
            && $request['TLD'] === 'com');
    }

    public function test_test_mode_uses_the_test_environment(): void
    {
        $this->fakeEnom();
        $this->enomDomains['acme.com'] = ['expires' => '2027-03-15'];

        $this->registrar(['test_mode' => true])->expiryDate('acme.com');

        Http::assertSent(fn (Request $request) => $request->url() === EnomClient::TEST_URL);
    }

    public function test_a_second_level_tld_is_split_after_the_first_label(): void
    {
        $this->assertSame(['SLD' => 'acme', 'TLD' => 'com.gr'], EnomRegistrar::split('Acme.com.gr.'));
    }

    public function test_register_sends_the_client_as_all_four_contacts(): void
    {
        $this->fakeEnom();
        $client = Client::factory()->create([
            'first_name' => 'Νίκος', 'last_name' => 'Παπαδόπουλος', 'company' => null,
            'email' => 'nikos@example.gr', 'phone' => '210 123 4567',
            'address1' => 'Ερμού 1', 'address2' => null, 'city' => 'Αθήνα', 'state' => 'Αττική', 'postcode' => '10563', 'country' => 'gr',
        ]);

        $this->registrar()->register('acme.com', 2, $client, ['ns1.host.gr', 'ns2.host.gr']);

        [$command, $params] = $this->enomCalls[0];
        $this->assertSame('Purchase', $command);
        $this->assertSame(['acme', 'com', '2', 'Yes', 'ns1.host.gr', 'ns2.host.gr'], [$params['SLD'], $params['TLD'], $params['NumYears'], $params['IgnoreNSFail'], $params['NS1'], $params['NS2']]);
        $this->assertArrayNotHasKey('UseDNS', $params);

        foreach (EnomRegistrar::CONTACTS as $prefix) {
            $this->assertSame('Νίκος', $params["{$prefix}FirstName"]);
            $this->assertSame('Παπαδόπουλος', $params["{$prefix}LastName"]);
            $this->assertSame('Ερμού 1', $params["{$prefix}Address1"]);
            $this->assertSame('Αθήνα', $params["{$prefix}City"]);
            $this->assertSame('Αττική', $params["{$prefix}StateProvince"]);
            $this->assertSame('10563', $params["{$prefix}PostalCode"]);
            $this->assertSame('GR', $params["{$prefix}Country"]);
            $this->assertSame('nikos@example.gr', $params["{$prefix}EmailAddress"]);
            $this->assertSame('+30.2101234567', $params["{$prefix}Phone"]);
            $this->assertArrayNotHasKey("{$prefix}OrganizationName", $params);
        }
    }

    public function test_a_company_adds_its_name_job_title_and_fax(): void
    {
        $this->fakeEnom();
        $client = Client::factory()->create(['company' => 'Acme ΙΚΕ', 'phone' => '2101234567']);

        $this->registrar()->register('acme.com', 1, $client, []);

        $params = $this->enomCalls[0][1];
        $this->assertSame(['Acme ΙΚΕ', 'N/A', '+30.2101234567'], [$params['RegistrantOrganizationName'], $params['RegistrantJobTitle'], $params['RegistrantFax']]);
        $this->assertSame('default', $params['UseDNS']);
    }

    public function test_transfer_sends_the_epp_code_and_keeps_it_out_of_the_module_log(): void
    {
        config(['gaiacp.debug.module_log' => true]);
        $this->fakeEnom();

        $this->registrar()->transfer('acme.com', 'Auth&Xq9z', Client::factory()->create());

        [$command, $params] = $this->enomCalls[0];
        $this->assertSame('TP_CreateOrder', $command);
        $this->assertSame(['Autoverification', '1', 'acme', 'com', 'Auth&Xq9z', '0'], [$params['OrderType'], $params['DomainCount'], $params['SLD1'], $params['TLD1'], $params['AuthInfo1'], $params['UseContacts']]);
        $this->assertArrayHasKey('RegistrantEmailAddress', $params);

        $log = ModuleLog::query()->where('module', 'enom')->sole();
        $this->assertSame('TP_CreateOrder', $log->action);
        $this->assertStringNotContainsString('Xq9z', $log->request);
        $this->assertStringNotContainsString('secret', $log->request.$log->url);
    }

    public function test_renews_and_reads_the_new_expiry(): void
    {
        $this->fakeEnom();
        $this->enomDomains['acme.com'] = ['expires' => '2027-03-15'];

        $this->registrar()->renew('acme.com', 2);

        $this->assertSame(['Extend', ['SLD' => 'acme', 'TLD' => 'com', 'NumYears' => '2']], $this->enomCalls[0]);
        $this->assertSame('2029-03-15', $this->registrar()->expiryDate('acme.com')->toDateString());
    }

    public function test_reads_and_saves_nameservers(): void
    {
        $this->fakeEnom();
        $this->enomDomains['acme.com'] = ['expires' => '2027-03-15', 'ns' => ['NS1.Host.gr', 'ns2.host.gr']];

        $this->assertSame(['ns1.host.gr', 'ns2.host.gr'], $this->registrar()->nameservers('acme.com'));

        $this->registrar()->setNameservers('acme.com', ['a.ns.gr', 'b.ns.gr', 'c.ns.gr']);
        $this->assertSame(['ModifyNS', ['SLD' => 'acme', 'TLD' => 'com', 'NS1' => 'a.ns.gr', 'NS2' => 'b.ns.gr', 'NS3' => 'c.ns.gr']], $this->enomCalls[1]);
    }

    public function test_errors_are_joined(): void
    {
        Http::fake(['*' => Http::response('<interface-response><ErrCount>2</ErrCount><errors><Err1>Domain name not available</Err1><Err2>Invalid years</Err2></errors></interface-response>')]);

        $this->expectExceptionMessage('Enom: Domain name not available, Invalid years');
        $this->registrar()->renew('acme.com', 1);
    }

    public function test_unexpected_response_is_an_error(): void
    {
        Http::fake(['*' => Http::response('<html>maintenance</html>')]);

        $this->expectExceptionMessage('unexpected response');
        $this->registrar()->expiryDate('acme.com');
    }

    public function test_missing_credentials_never_call_the_api(): void
    {
        $registrar = $this->registrar(['uid' => '']);

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
        $this->fakeEnom();
        config(['gaiacp.domains.nameservers' => ['ns1.host.gr', 'ns2.host.gr']]);
        $service = Service::factory()->domain()->create(['domain' => 'acme.com', 'status' => ServiceStatus::Pending, 'start_date' => '2026-10-09', 'next_due_date' => '2026-10-09']);
        $service->domainDetails()->create(['registration_type' => DomainRegistrationType::Register]);

        app(DomainLifecycle::class)->activate($service->refresh());

        $this->assertSame(['GetDomainInfo', 'Purchase', 'GetDomainInfo', 'GetDNS'], $this->enomCommands());
        $this->assertSame(ServiceStatus::Active, $service->refresh()->status);
        $this->assertSame('2027-10-09', $service->domainDetails->expires_at->toDateString());
    }

    public function test_the_epp_code_is_emailed_to_the_registrant(): void
    {
        $this->fakeEnom();
        $service = $this->activeDomain();

        $this->assertTrue(app(DomainLifecycle::class)->emailsEppCode($service));
        $this->assertNull(app(DomainLifecycle::class)->eppCode($service));

        $this->assertSame(['SynchAuthInfo', ['SLD' => 'acme', 'TLD' => 'com', 'EmailEPP' => 'True', 'RunSynchAutoInfo' => 'True']], $this->enomCalls[0]);
        $this->assertSame('EPP code of acme.com emailed to the registrant by Enom', ActivityLog::query()->where('event', 'domain.epp_requested')->sole()->description);
    }

    public function test_the_admin_is_told_the_code_was_emailed(): void
    {
        $this->fakeEnom();
        $service = $this->activeDomain();
        $this->actingAs(User::factory()->create());

        Livewire::test(EditService::class, ['record' => $service->id])
            ->mountAction('eppCode')
            ->assertMountedActionModalSee(__('Asks the registrar to email the transfer-out code to the domain owner\'s email address. Nothing is changed.'))
            ->callMountedAction()
            ->assertNotified(__('EPP code emailed'));
    }

    public function test_the_client_is_told_the_code_was_emailed(): void
    {
        $this->fakeEnom();
        $service = $this->activeDomain();
        $user = User::factory()->create(['role' => UserRole::Client, 'client_id' => $service->client_id, 'email' => $service->client->email]);
        Filament::setCurrentPanel('client');
        $this->actingAs($user, 'client');

        Livewire::test(ViewDomain::class, ['record' => $service->getRouteKey()])
            ->mountAction('getEppCode')
            ->assertMountedActionModalSee(__('Request the code'))
            ->callMountedAction()
            ->assertNotified(__('EPP code requested'));

        $this->assertSame(['SynchAuthInfo'], $this->enomCommands());
    }

    public function test_create_an_enom_account(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(CreateRegistrar::class)
            ->fillForm(['module' => 'enom'])
            ->fillForm([
                'name' => 'Enom',
                'credentials' => ['uid' => 'reseller', 'password' => 'token-1', 'test_mode' => true],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $registrar = Registrar::query()->where('module', 'enom')->sole();
        $this->assertSame(['uid' => 'reseller', 'password' => 'token-1', 'test_mode' => true], $registrar->credentials);
        $this->assertInstanceOf(EnomRegistrar::class, app(RegistrarFactory::class)->forRegistrar($registrar));
    }

    public function test_at_enom_the_admin_changes_the_lock_and_four_contacts(): void
    {
        $this->fakeEnom();
        $domain = $this->activeDomain();
        $this->actingAs(User::factory()->create());

        Livewire::test(EditService::class, ['record' => $domain->id])
            ->mountAction('registrarLock')
            ->assertSchemaStateSet(['locked' => true])
            ->fillForm(['locked' => false])
            ->callMountedAction()
            ->assertNotified(__('The domain is unlocked'));
        $this->assertFalse($this->enomDomains['acme.com']['locked']);
        $this->assertSame('Registrar lock off for acme.com', ActivityLog::query()->where('event', 'domain.lock_changed')->value('description'));

        Livewire::test(EditService::class, ['record' => $domain->id])
            ->mountAction('domainContacts')
            ->assertSchemaStateSet(['contacts.registrant.first_name' => 'John', 'contacts.billing.first_name' => 'Bill', 'contacts.registrant.company' => 'Acme Inc'])
            ->callMountedAction()
            ->assertHasNoFormErrors();

        $this->assertSame(['REGISTRANT', 'ADMIN', 'TECH', 'AUXBILLING'], array_keys($this->enomDomains['acme.com']['contacts']));
        $this->assertSame('Acme Inc', $this->enomDomains['acme.com']['contacts']['AUXBILLING']['AuxBillingOrganizationName']);
        $this->assertSame('+1.6175551234', $this->enomDomains['acme.com']['contacts']['REGISTRANT']['RegistrantPhone']);

        Livewire::test(EditService::class, ['record' => $domain->id])
            ->callAction('childNameservers', ['operation' => 'save', 'host' => 'ns1', 'ip' => '192.0.2.10'])
            ->callAction('childNameservers', ['operation' => 'save', 'host' => 'ns1', 'ip' => '192.0.2.20']);
        $this->assertSame(['ns1.acme.com' => '192.0.2.20'], $this->enomHosts);
        $this->assertContains('UpdateNameServer', $this->enomCommands());
    }
}
