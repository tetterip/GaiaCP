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
use App\Provisioning\DomainRegistrar;
use App\Provisioning\ProvisioningException;
use App\Provisioning\RegistrarFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Plugins\Openprovider\OpenproviderClient;
use Plugins\Openprovider\OpenproviderRegistrar;
use Tests\FakesOpenprovider;
use Tests\TestCase;

class OpenproviderRegistrarTest extends TestCase
{
    use FakesOpenprovider, RefreshDatabase;

    /** @return OpenproviderRegistrar */
    private function registrar(array $credentials = []): DomainRegistrar
    {
        return app(RegistrarFactory::class)->make('openprovider', [...['username' => 'reseller', 'password' => 'secret'], ...$credentials]);
    }

    private function holds(string $domain = 'acme.com', array $fields = []): void
    {
        $this->openproviderCustomers['JS000001-NL'] = [
            'name' => ['first_name' => 'John', 'last_name' => 'Smith'], 'company_name' => 'Acme Inc', 'email' => 'john@acme.com',
            'phone' => ['country_code' => '+1', 'area_code' => '617', 'subscriber_number' => '5551234'],
            'address' => ['street' => 'Main St', 'number' => '1', 'zipcode' => '02101', 'city' => 'Boston', 'state' => 'MA', 'country' => 'US'],
        ];
        $this->openproviderDomains[$domain] = [
            'id' => 9 + count($this->openproviderDomains), 'expiration_date' => '2027-03-15 10:00:00', 'is_locked' => true,
            'name_servers' => [['name' => 'ns2.host.gr', 'seq_nr' => 2], ['name' => 'NS1.Host.gr', 'seq_nr' => 1]],
            'owner_handle' => 'JS000001-NL', 'admin_handle' => 'JS000001-NL', 'tech_handle' => 'JS000001-NL', 'billing_handle' => 'JS000001-NL',
            ...$fields,
        ];
    }

    private function activeDomain(string $domain = 'acme.com'): Service
    {
        $service = Service::factory()->domain()->create(['domain' => $domain, 'next_due_date' => '2027-03-15']);
        $service->domainDetails()->create(['registration_type' => DomainRegistrationType::Register, 'registrar_id' => $this->openproviderRegistrar->id]);
        $this->holds($domain);

        return $service->refresh();
    }

    public function test_logs_in_once_and_reads_the_domain_with_the_token(): void
    {
        $this->fakeOpenprovider();
        $this->holds();
        $registrar = $this->registrar();

        $this->assertSame('2027-03-15', $registrar->expiryDate('Acme.com')->toDateString());
        $this->assertSame(['ns1.host.gr', 'ns2.host.gr'], $registrar->nameservers('acme.com'));
        $this->assertTrue($registrar->locked('acme.com'));

        $this->assertSame(1, $this->openproviderLogins);
        $this->assertSame(['GET domains'], $this->openproviderCallNames());
        Http::assertSent(fn (Request $request) => $request->url() === OpenproviderClient::LIVE_URL.'/auth/login' && $request['username'] === 'reseller');
    }

    public function test_sandbox_uses_the_sandbox_url(): void
    {
        $this->fakeOpenprovider();
        $this->holds();

        $this->registrar(['sandbox' => true])->expiryDate('acme.com');

        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), OpenproviderClient::SANDBOX_URL.'/domains'));
    }

    public function test_a_domain_not_in_the_account_is_an_error(): void
    {
        $this->fakeOpenprovider();

        $this->expectExceptionMessage('acme.com is not in the Openprovider account.');
        $this->registrar()->expiryDate('acme.com');
    }

    public function test_register_makes_the_client_a_customer_handle_for_all_four_roles(): void
    {
        $this->fakeOpenprovider();
        $client = Client::factory()->create([
            'first_name' => 'Νίκος', 'last_name' => 'Παπαδόπουλος', 'company' => null, 'email' => 'nikos@example.gr', 'phone' => '210 123 4567',
            'address1' => 'Ερμού 12', 'address2' => null, 'city' => 'Αθήνα', 'state' => 'Αττική', 'postcode' => '10563', 'country' => 'gr',
        ]);

        $this->registrar()->register('acme.com', 2, $client, ['ns1.host.gr', 'ns2.host.gr']);

        [[$call, $customer], [$register, $body]] = $this->openproviderCalls;
        $this->assertSame('POST customers', $call);
        $this->assertSame([
            'name' => ['first_name' => 'Νίκος', 'last_name' => 'Παπαδόπουλος'], 'email' => 'nikos@example.gr',
            'phone' => ['country_code' => '+30', 'area_code' => '210', 'subscriber_number' => '1234567'],
            'address' => ['street' => 'Ερμού', 'number' => '12', 'zipcode' => '10563', 'city' => 'Αθήνα', 'state' => 'Αττική', 'country' => 'GR'],
        ], $customer);
        $this->assertSame('POST domains', $register);
        $this->assertSame(['name' => 'acme', 'extension' => 'com'], $body['domain']);
        $this->assertSame([2, 'off', 'XX100-NL', 'XX100-NL'], [$body['period'], $body['autorenew'], $body['owner_handle'], $body['billing_handle']]);
        $this->assertSame([['name' => 'ns1.host.gr', 'seq_nr' => 1], ['name' => 'ns2.host.gr', 'seq_nr' => 2]], $body['name_servers']);
    }

    public function test_register_without_nameservers_uses_openproviders(): void
    {
        $this->fakeOpenprovider();

        $this->registrar()->register('acme.com', 1, Client::factory()->create(), []);

        $this->assertSame(OpenproviderRegistrar::DEFAULT_NS_GROUP, $this->openproviderCalls[1][1]['ns_group']);
    }

    public function test_street_and_number_are_split(): void
    {
        $this->assertSame(['Ερμού', '12'], OpenproviderRegistrar::streetAndNumber('Ερμού 12'));
        $this->assertSame(['Main St', '1'], OpenproviderRegistrar::streetAndNumber('1 Main St'));
        $this->assertSame(['Λεωφ. Κηφισίας', '120Α'], OpenproviderRegistrar::streetAndNumber('Λεωφ. Κηφισίας, 120Α'));
        $this->assertSame(['PO Box', ''], OpenproviderRegistrar::streetAndNumber('PO Box'));
    }

    public function test_transfer_keeps_the_epp_code_password_and_token_out_of_the_module_log(): void
    {
        config(['gaiacp.debug.module_log' => true]);
        $this->fakeOpenprovider();

        $this->registrar()->transfer('acme.com', 'Auth&Xq9z', Client::factory()->create());

        [$call, $body] = $this->openproviderCalls[1];
        $this->assertSame('POST domains/transfer', $call);
        $this->assertSame(['Auth&Xq9z', 1], [$body['auth_code'], $body['period']]);

        $logs = ModuleLog::query()->where('module', 'openprovider')->get();
        $this->assertSame(['POST /auth/login', 'POST /customers', 'POST /domains/transfer'], $logs->pluck('action')->all());
        $text = $logs->map(fn ($log) => $log->url.$log->request.$log->response)->implode('');
        $this->assertStringNotContainsString('Xq9z', $text);
        $this->assertStringNotContainsString('secret', $text);
        $this->assertStringNotContainsString('tok-123', $text);
    }

    public function test_renews_and_reads_the_new_expiry(): void
    {
        $this->fakeOpenprovider();
        $this->holds();
        $registrar = $this->registrar();

        $registrar->renew('acme.com', 2);

        $this->assertSame(['POST domains/9/renew', ['domain' => ['name' => 'acme', 'extension' => 'com'], 'period' => 2]], $this->openproviderCalls[1]);
        $this->assertSame('2029-03-15', $registrar->expiryDate('acme.com')->toDateString());
    }

    public function test_saves_nameservers_and_gives_the_epp_code(): void
    {
        $this->fakeOpenprovider();
        $this->holds();

        $this->registrar()->setNameservers('acme.com', ['a.ns.gr', 'b.ns.gr']);
        $this->assertSame(['PUT domains/9', ['domain' => ['name' => 'acme', 'extension' => 'com'], 'name_servers' => [['name' => 'a.ns.gr', 'seq_nr' => 1], ['name' => 'b.ns.gr', 'seq_nr' => 2]]]], $this->openproviderCalls[1]);

        $this->assertSame('Op#Code9', $this->registrar()->eppCode('acme.com'));
    }

    public function test_availability_and_premium_names(): void
    {
        $this->fakeOpenprovider();
        $this->holds('taken.com');

        $this->assertTrue($this->registrar()->available('free.com'));
        $this->assertFalse($this->registrar()->available('taken.com'));
        $this->assertFalse($this->registrar()->available('premium.com'));
        $this->assertSame(['domains' => [['name' => 'free', 'extension' => 'com']], 'with_price' => false], $this->openproviderCalls[0][1]);
    }

    public function test_tld_prices_are_the_reseller_prices_of_the_tlds_named(): void
    {
        $this->fakeOpenprovider();
        $this->openproviderPrices = ['com' => ['create_price' => 8.5, 'renew_price' => 9.25, 'transfer_price' => 8.5, 'restore_price' => 75]];

        $prices = $this->registrar()->tldPrices(['.COM', '.xyz']);

        $this->assertCount(1, $prices);
        $this->assertSame(['tld' => '.com', 'years' => 1, 'currency' => 'EUR', 'register' => 850, 'renew' => 925, 'transfer' => 850, 'redemption' => 7500], $prices[0]->toArray());
    }

    public function test_errors_show_the_reason(): void
    {
        $this->fakeOpenprovider();

        $this->expectExceptionMessage('Openprovider: Authentication/Authorization Failed');
        $this->registrar(['password' => 'wrong'])->expiryDate('acme.com');
    }

    public function test_unexpected_response_is_an_error(): void
    {
        Http::fake(['*' => Http::response('<html>maintenance</html>', 502)]);

        $this->expectExceptionMessage('failed with HTTP 502');
        $this->registrar()->expiryDate('acme.com');
    }

    public function test_missing_credentials_never_call_the_api(): void
    {
        $registrar = $this->registrar(['password' => '']);

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
        $this->fakeOpenprovider();
        config(['gaiacp.domains.nameservers' => ['ns1.host.gr', 'ns2.host.gr']]);
        $service = Service::factory()->domain()->create(['domain' => 'acme.com', 'status' => ServiceStatus::Pending, 'start_date' => '2026-10-09', 'next_due_date' => '2026-10-09']);
        $service->domainDetails()->create(['registration_type' => DomainRegistrationType::Register, 'registrar_id' => $this->openproviderRegistrar->id]);

        app(DomainLifecycle::class)->activate($service->refresh());

        $this->assertSame(ServiceStatus::Active, $service->refresh()->status);
        $this->assertSame('2027-10-09', $service->domainDetails->expires_at->toDateString());
        $this->assertContains('POST domains', $this->openproviderCallNames());
    }

    public function test_create_an_openprovider_account(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(CreateRegistrar::class)
            ->fillForm(['module' => 'openprovider'])
            ->fillForm(['name' => 'Openprovider', 'credentials' => ['username' => 'reseller', 'password' => 'pass-1', 'sandbox' => true]])
            ->call('create')
            ->assertHasNoFormErrors();

        $registrar = Registrar::query()->where('module', 'openprovider')->sole();
        $this->assertSame(['username' => 'reseller', 'password' => 'pass-1', 'sandbox' => true], $registrar->credentials);
        $this->assertInstanceOf(OpenproviderRegistrar::class, app(RegistrarFactory::class)->forRegistrar($registrar));
    }

    public function test_the_admin_changes_the_lock_contacts_and_private_nameservers(): void
    {
        $this->fakeOpenprovider();
        $domain = $this->activeDomain();
        $this->actingAs(User::factory()->create());

        Livewire::test(EditService::class, ['record' => $domain->id])
            ->mountAction('registrarLock')
            ->assertSchemaStateSet(['locked' => true])
            ->fillForm(['locked' => false])
            ->callMountedAction()
            ->assertNotified(__('The domain is unlocked'));
        $this->assertFalse($this->openproviderDomains['acme.com']['is_locked']);
        $this->assertSame('Registrar lock off for acme.com', ActivityLog::query()->where('event', 'domain.lock_changed')->value('description'));

        Livewire::test(EditService::class, ['record' => $domain->id])
            ->mountAction('domainContacts')
            ->assertSchemaStateSet(['contacts.registrant.first_name' => 'John', 'contacts.registrant.address1' => 'Main St 1', 'contacts.billing.company' => 'Acme Inc'])
            ->fillForm(['contacts.tech.first_name' => 'Tom'])
            ->callMountedAction()
            ->assertHasNoFormErrors();

        $record = $this->openproviderDomains['acme.com'];
        $this->assertSame($record['owner_handle'], $record['admin_handle']);
        $this->assertNotSame($record['owner_handle'], $record['tech_handle']);
        $this->assertNotSame('JS000001-NL', $record['owner_handle']);
        $this->assertSame('Tom', $this->openproviderCustomers[$record['tech_handle']]['name']['first_name']);
        $this->assertSame(['country_code' => '+1', 'area_code' => '617', 'subscriber_number' => '5551234'], $this->openproviderCustomers[$record['owner_handle']]['phone']);

        Livewire::test(EditService::class, ['record' => $domain->id])
            ->callAction('childNameservers', ['operation' => 'save', 'host' => 'ns1', 'ip' => '192.0.2.10'])
            ->callAction('childNameservers', ['operation' => 'save', 'host' => 'ns1', 'ip' => '192.0.2.20']);
        $this->assertSame(['ns1.acme.com' => '192.0.2.20'], $this->openproviderHosts);
        $this->assertContains('PUT dns/nameservers/ns1.acme.com', $this->openproviderCallNames());
    }
}
