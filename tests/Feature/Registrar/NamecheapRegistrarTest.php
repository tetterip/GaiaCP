<?php

namespace Tests\Feature\Registrar;

use App\Actions\DomainLifecycle;
use App\Enums\DomainRegistrationType;
use App\Enums\ServiceStatus;
use App\Filament\Resources\Registrars\Pages\CreateRegistrar;
use App\Filament\Resources\Services\Pages\EditService;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\ModuleLog;
use App\Models\Registrar;
use App\Models\Service;
use App\Models\User;
use App\Provisioning\DomainContact;
use App\Provisioning\DomainRegistrar;
use App\Provisioning\ProvisioningException;
use App\Provisioning\RegistrarFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Plugins\Namecheap\NamecheapClient;
use Plugins\Namecheap\NamecheapRegistrar;
use Tests\FakesNamecheap;
use Tests\TestCase;

class NamecheapRegistrarTest extends TestCase
{
    use FakesNamecheap, RefreshDatabase;

    /** @return NamecheapRegistrar */
    private function registrar(array $credentials = []): DomainRegistrar
    {
        return app(RegistrarFactory::class)->make('namecheap', [...['username' => 'reseller', 'api_key' => 'secret', 'client_ip' => '192.0.2.1'], ...$credentials]);
    }

    private function activeDomain(string $domain = 'acme.com'): Service
    {
        $service = Service::factory()->domain()->create(['domain' => $domain, 'next_due_date' => '2027-03-15']);
        $service->domainDetails()->create(['registration_type' => DomainRegistrationType::Register, 'registrar_id' => $this->namecheapRegistrar->id]);
        $this->namecheapDomains[$domain] = ['expires' => '2027-03-15', 'ns' => ['ns1.host.gr', 'ns2.host.gr']];

        return $service->refresh();
    }

    public function test_posts_the_login_and_command_as_a_form(): void
    {
        $this->fakeNamecheap();
        $this->namecheapDomains['acme.com'] = ['expires' => '2027-03-15'];

        $this->assertSame('2027-03-15', $this->registrar()->expiryDate('Acme.com')->toDateString());

        Http::assertSent(fn (Request $request) => $request->url() === NamecheapClient::LIVE_URL
            && $request->method() === 'POST'
            && $request->isForm()
            && $request['ApiUser'] === 'reseller'
            && $request['UserName'] === 'reseller'
            && $request['ApiKey'] === 'secret'
            && $request['ClientIp'] === '192.0.2.1'
            && $request['Command'] === 'namecheap.domains.getInfo'
            && $request['DomainName'] === 'acme.com');
    }

    public function test_sandbox_uses_the_sandbox_url(): void
    {
        $this->fakeNamecheap();
        $this->namecheapDomains['acme.com'] = ['expires' => '2027-03-15'];

        $this->registrar(['sandbox' => true])->expiryDate('acme.com');

        Http::assertSent(fn (Request $request) => $request->url() === NamecheapClient::SANDBOX_URL);
    }

    public function test_without_an_ip_address_it_sends_the_one_namecheaps_module_sends(): void
    {
        $this->fakeNamecheap();
        $this->namecheapDomains['acme.com'] = ['expires' => '2027-03-15'];

        $this->registrar(['client_ip' => null])->expiryDate('acme.com');

        Http::assertSent(fn (Request $request) => $request['ClientIp'] === NamecheapClient::FALLBACK_CLIENT_IP);
    }

    public function test_a_second_level_tld_is_split_after_the_first_label(): void
    {
        $this->assertSame(['SLD' => 'acme', 'TLD' => 'co.uk'], NamecheapRegistrar::split('Acme.co.uk.'));
    }

    public function test_register_sends_the_client_as_all_four_contacts(): void
    {
        $this->fakeNamecheap();
        $client = Client::factory()->create([
            'first_name' => 'Νίκος', 'last_name' => 'Παπαδόπουλος', 'company' => null,
            'email' => 'nikos@example.gr', 'phone' => '210 123 4567',
            'address1' => 'Ερμού 1', 'address2' => null, 'city' => 'Αθήνα', 'state' => 'Αττική', 'postcode' => '10563', 'country' => 'gr',
        ]);

        $this->registrar()->register('acme.com', 2, $client, ['ns1.host.gr', 'ns2.host.gr']);

        [$command, $params] = $this->namecheapCalls[0];
        $this->assertSame('namecheap.domains.create', $command);
        $this->assertSame(['acme.com', '2', 'ns1.host.gr,ns2.host.gr', 'yes'], [$params['DomainName'], $params['Years'], $params['Nameservers'], $params['AddFreeWhoisguard']]);

        foreach (NamecheapRegistrar::ROLES as $prefix) {
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
            $this->assertArrayNotHasKey("{$prefix}Address2", $params);
        }
    }

    public function test_register_without_nameservers_leaves_namecheaps_own(): void
    {
        $this->fakeNamecheap();

        $this->registrar()->register('acme.com', 1, Client::factory()->create(['company' => 'Acme ΙΚΕ']), []);

        $params = $this->namecheapCalls[0][1];
        $this->assertArrayNotHasKey('Nameservers', $params);
        $this->assertSame('Acme ΙΚΕ', $params['RegistrantOrganizationName']);
    }

    public function test_transfer_sends_the_epp_code_and_keeps_it_out_of_the_module_log(): void
    {
        config(['gaiacp.debug.module_log' => true]);
        $this->fakeNamecheap();

        $this->registrar()->transfer('acme.com', 'Auth&Xq9z', Client::factory()->create());

        $this->assertSame(['namecheap.domains.transfer.create', ['DomainName' => 'acme.com', 'Years' => '1', 'EPPCode' => 'base64:'.base64_encode('Auth&Xq9z')]], $this->namecheapCalls[0]);

        $log = ModuleLog::query()->where('module', 'namecheap')->sole();
        $this->assertSame('namecheap.domains.transfer.create', $log->action);
        $this->assertStringNotContainsString('Xq9z', $log->request);
        $this->assertStringNotContainsString(base64_encode('Auth&Xq9z'), $log->request);
        $this->assertStringNotContainsString('secret', $log->request.$log->url);
    }

    public function test_renews_and_reads_the_new_expiry(): void
    {
        $this->fakeNamecheap();
        $this->namecheapDomains['acme.com'] = ['expires' => '2027-03-15'];

        $this->registrar()->renew('acme.com', 2);

        $this->assertSame(['namecheap.domains.renew', ['DomainName' => 'acme.com', 'Years' => '2']], $this->namecheapCalls[0]);
        $this->assertSame('2029-03-15', $this->registrar()->expiryDate('acme.com')->toDateString());
    }

    public function test_reads_and_saves_nameservers(): void
    {
        $this->fakeNamecheap();
        $this->namecheapDomains['acme.com'] = ['expires' => '2027-03-15', 'ns' => ['NS1.Host.gr', 'ns2.host.gr']];

        $this->assertSame(['ns1.host.gr', 'ns2.host.gr'], $this->registrar()->nameservers('acme.com'));

        $this->registrar()->setNameservers('acme.com', ['a.ns.gr', 'b.ns.gr', 'c.ns.gr']);
        $this->assertSame(['namecheap.domains.dns.setCustom', ['SLD' => 'acme', 'TLD' => 'com', 'Nameservers' => 'a.ns.gr,b.ns.gr,c.ns.gr']], $this->namecheapCalls[1]);

        $this->registrar()->setNameservers('acme.com', []);
        $this->assertSame('namecheap.domains.dns.setDefault', $this->namecheapCalls[2][0]);
    }

    public function test_availability_and_premium_names(): void
    {
        $this->fakeNamecheap();
        $this->namecheapDomains['taken.com'] = ['expires' => '2027-03-15'];

        $this->assertTrue($this->registrar()->available('free.com'));
        $this->assertFalse($this->registrar()->available('taken.com'));
        $this->assertFalse($this->registrar()->available('premium.com'));
        $this->assertSame(['DomainList' => 'free.com'], $this->namecheapCalls[0][1]);
    }

    public function test_tld_prices_are_the_one_year_prices_of_every_tld(): void
    {
        $this->fakeNamecheap();
        $this->namecheapPrices = [
            'com' => ['register' => '9.58', 'renew' => '13.98', 'transfer' => '9.58'],
            'net' => ['register' => '11.48', 'renew' => '14.98'],
        ];

        $prices = collect($this->registrar()->tldPrices())->keyBy('tld');

        $this->assertSame(['.com', '.net'], $prices->keys()->all());
        $this->assertSame([958, 1398, 958, 'USD', 1], [$prices['.com']->registerCents, $prices['.com']->renewCents, $prices['.com']->transferCents, $prices['.com']->currency, $prices['.com']->years]);
        $this->assertNull($prices['.net']->transferCents);
        $this->assertSame(['namecheap.users.getPricing', ['ProductType' => 'DOMAIN', 'ProductCategory' => 'REGISTER']], $this->namecheapCalls[0]);

        $this->assertSame(['.net'], array_column(array_map(fn ($p) => $p->toArray(), $this->registrar()->tldPrices(['NET'])), 'tld'));
    }

    public function test_errors_are_joined(): void
    {
        Http::fake(['*' => Http::response('<?xml version="1.0"?><ApiResponse Status="ERROR" xmlns="http://api.namecheap.com/xml.response"><Errors>'
            .'<Error Number="1011150">Parameter RequestIP is invalid</Error><Error Number="2">Second</Error></Errors></ApiResponse>')]);

        $this->expectExceptionMessage('Namecheap: Parameter RequestIP is invalid, Second');
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

    public function test_the_epp_code_is_not_given_by_the_api(): void
    {
        $this->expectException(ProvisioningException::class);
        $this->expectExceptionMessage('Namecheap account');

        $this->registrar()->eppCode('acme.com');
    }

    public function test_registering_through_the_domain_lifecycle(): void
    {
        $this->travelTo('2026-10-09 10:00');
        $this->fakeNamecheap();
        config(['gaiacp.domains.nameservers' => ['ns1.host.gr', 'ns2.host.gr']]);
        $service = Service::factory()->domain()->create(['domain' => 'acme.com', 'status' => ServiceStatus::Pending, 'start_date' => '2026-10-09', 'next_due_date' => '2026-10-09']);
        $service->domainDetails()->create(['registration_type' => DomainRegistrationType::Register, 'registrar_id' => $this->namecheapRegistrar->id]);

        app(DomainLifecycle::class)->activate($service->refresh());

        $this->assertSame(['namecheap.domains.getInfo', 'namecheap.domains.create', 'namecheap.domains.getInfo', 'namecheap.domains.dns.getList'], $this->namecheapCommands());
        $this->assertSame(ServiceStatus::Active, $service->refresh()->status);
        $this->assertSame('2027-10-09', $service->domainDetails->expires_at->toDateString());
    }

    public function test_saving_some_contacts_keeps_the_others(): void
    {
        $this->fakeNamecheap();
        $this->namecheapDomains['acme.com'] = ['expires' => '2027-03-15'];

        $this->registrar()->saveContacts('acme.com', ['registrant' => DomainContact::fromArray(['first_name' => 'Anna', 'last_name' => 'Lee', 'country' => 'gr', 'phone' => '6912345678'])]);

        $saved = $this->namecheapDomains['acme.com']['contacts'];
        $this->assertSame(['Anna', '+30.6912345678', 'Jane', 'Bill'], [$saved['RegistrantFirstName'], $saved['RegistrantPhone'], $saved['AdminFirstName'], $saved['AuxBillingFirstName']]);
    }

    public function test_create_a_namecheap_account(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(CreateRegistrar::class)
            ->fillForm(['module' => 'namecheap'])
            ->fillForm([
                'name' => 'Namecheap',
                'credentials' => ['username' => 'reseller', 'api_key' => 'key-1', 'client_ip' => '192.0.2.1', 'sandbox' => true],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $registrar = Registrar::query()->where('module', 'namecheap')->sole();
        $this->assertSame(['username' => 'reseller', 'api_key' => 'key-1', 'client_ip' => '192.0.2.1', 'sandbox' => true], $registrar->credentials);
        $this->assertInstanceOf(NamecheapRegistrar::class, app(RegistrarFactory::class)->forRegistrar($registrar));
    }

    public function test_the_admin_changes_the_lock_contacts_and_private_nameservers(): void
    {
        $this->fakeNamecheap();
        $domain = $this->activeDomain();
        $this->actingAs(User::factory()->create());

        Livewire::test(EditService::class, ['record' => $domain->id])
            ->mountAction('registrarLock')
            ->assertSchemaStateSet(['locked' => true])
            ->fillForm(['locked' => false])
            ->callMountedAction()
            ->assertNotified(__('The domain is unlocked'));
        $this->assertFalse($this->namecheapDomains['acme.com']['locked']);
        $this->assertSame('Registrar lock off for acme.com', ActivityLog::query()->where('event', 'domain.lock_changed')->value('description'));

        Livewire::test(EditService::class, ['record' => $domain->id])
            ->mountAction('domainContacts')
            ->assertSchemaStateSet(['contacts.registrant.first_name' => 'John', 'contacts.billing.first_name' => 'Bill', 'contacts.registrant.company' => 'Acme Inc'])
            ->callMountedAction()
            ->assertHasNoFormErrors();
        $this->assertSame('Acme Inc', $this->namecheapDomains['acme.com']['contacts']['AuxBillingOrganizationName']);
        $this->assertSame('+1.6175551234', $this->namecheapDomains['acme.com']['contacts']['RegistrantPhone']);

        Livewire::test(EditService::class, ['record' => $domain->id])
            ->callAction('childNameservers', ['operation' => 'save', 'host' => 'ns1', 'ip' => '192.0.2.10'])
            ->callAction('childNameservers', ['operation' => 'save', 'host' => 'ns1', 'ip' => '192.0.2.20']);
        $this->assertSame(['ns1.acme.com' => '192.0.2.20'], $this->namecheapHosts);
        $this->assertContains('namecheap.domains.ns.update', $this->namecheapCommands());
    }
}
