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
use Plugins\NameSilo\NameSiloClient;
use Plugins\NameSilo\NameSiloRegistrar;
use Tests\FakesNameSilo;
use Tests\TestCase;

class NameSiloRegistrarTest extends TestCase
{
    use FakesNameSilo, RefreshDatabase;

    /** @return NameSiloRegistrar */
    private function registrar(array $credentials = []): DomainRegistrar
    {
        return app(RegistrarFactory::class)->make('namesilo', [...['api_key' => 'secret'], ...$credentials]);
    }

    private function activeDomain(string $domain = 'acme.com'): Service
    {
        $service = Service::factory()->domain()->create(['domain' => $domain, 'next_due_date' => '2027-03-15']);
        $service->domainDetails()->create(['registration_type' => DomainRegistrationType::Register, 'registrar_id' => $this->namesiloRegistrar->id]);
        $this->namesiloContacts['7'] = ['first_name' => 'John', 'last_name' => 'Smith', 'company' => 'Acme Inc', 'address' => '1 Main St', 'city' => 'Boston',
            'state' => 'MA', 'zip' => '02101', 'country' => 'US', 'email' => 'john@acme.com', 'phone' => '16175551234'];
        $this->namesiloDomains[$domain] = ['expires' => '2027-03-15', 'ns' => ['ns1.host.gr', 'ns2.host.gr'],
            'contacts' => ['registrant' => '7', 'administrative' => '7', 'technical' => '7', 'billing' => '7']];

        return $service->refresh();
    }

    public function test_gets_the_operation_with_the_key(): void
    {
        $this->fakeNameSilo();
        $this->namesiloDomains['acme.com'] = ['expires' => '2027-03-15'];

        $this->assertSame('2027-03-15', $this->registrar()->expiryDate('Acme.com')->toDateString());

        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), NameSiloClient::LIVE_URL.'getDomainInfo?')
            && $request->method() === 'GET'
            && (string) $request['version'] === '1'
            && $request['type'] === 'xml'
            && $request['key'] === 'secret'
            && $request['domain'] === 'acme.com');
    }

    public function test_sandbox_uses_the_sandbox_url(): void
    {
        $this->fakeNameSilo();
        $this->namesiloDomains['acme.com'] = ['expires' => '2027-03-15'];

        $this->registrar(['sandbox' => true])->expiryDate('acme.com');

        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), NameSiloClient::SANDBOX_URL));
    }

    public function test_register_sends_the_client_with_privacy_and_without_auto_renew(): void
    {
        $this->fakeNameSilo();
        $client = Client::factory()->create([
            'first_name' => 'Νίκος', 'last_name' => 'Παπαδόπουλος', 'company' => 'Acme ΙΚΕ',
            'email' => 'nikos@example.gr', 'phone' => '210 123 4567',
            'address1' => 'Ερμού 1', 'address2' => null, 'city' => 'Αθήνα', 'state' => 'Αττική', 'postcode' => '10563', 'country' => 'gr',
        ]);

        $this->registrar()->register('acme.com', 2, $client, ['ns1.host.gr', 'ns2.host.gr']);

        [$operation, $params] = $this->namesiloCalls[0];
        $this->assertSame('registerDomain', $operation);
        $this->assertSame([
            'domain' => 'acme.com', 'years' => '2', 'private' => '1', 'auto_renew' => '0', 'ns1' => 'ns1.host.gr', 'ns2' => 'ns2.host.gr',
            'fn' => 'Νίκος', 'ln' => 'Παπαδόπουλος', 'ad' => 'Ερμού 1', 'cy' => 'Αθήνα', 'st' => 'Αττική', 'zp' => '10563', 'ct' => 'GR',
            'em' => 'nikos@example.gr', 'ph' => '2101234567', 'cp' => 'Acme ΙΚΕ',
        ], $params);
    }

    public function test_transfer_sends_the_epp_code_and_keeps_it_and_the_key_out_of_the_module_log(): void
    {
        config(['gaiacp.debug.module_log' => true]);
        $this->fakeNameSilo();

        $this->registrar()->transfer('acme.com', 'Auth&Xq9z', Client::factory()->create());

        [$operation, $params] = $this->namesiloCalls[0];
        $this->assertSame('transferDomain', $operation);
        $this->assertSame(['acme.com', 'Auth&Xq9z'], [$params['domain'], $params['auth']]);

        $log = ModuleLog::query()->where('module', 'namesilo')->sole();
        $this->assertSame('transferDomain', $log->action);
        $this->assertStringNotContainsString('Xq9z', $log->request.$log->url);
        $this->assertStringNotContainsString('secret', $log->request.$log->url);
    }

    public function test_renews_and_reads_the_new_expiry(): void
    {
        $this->fakeNameSilo();
        $this->namesiloDomains['acme.com'] = ['expires' => '2027-03-15'];

        $this->registrar()->renew('acme.com', 2);

        $this->assertSame(['renewDomain', ['domain' => 'acme.com', 'years' => '2']], $this->namesiloCalls[0]);
        $this->assertSame('2029-03-15', $this->registrar()->expiryDate('acme.com')->toDateString());
    }

    public function test_reads_and_saves_nameservers(): void
    {
        $this->fakeNameSilo();
        $this->namesiloDomains['acme.com'] = ['expires' => '2027-03-15', 'ns' => ['NS1.Host.gr', 'ns2.host.gr']];

        $this->assertSame(['ns1.host.gr', 'ns2.host.gr'], $this->registrar()->nameservers('acme.com'));

        $this->registrar()->setNameservers('acme.com', ['a.ns.gr', 'b.ns.gr', 'c.ns.gr']);
        $this->assertSame(['changeNameServers', ['domain' => 'acme.com', 'ns1' => 'a.ns.gr', 'ns2' => 'b.ns.gr', 'ns3' => 'c.ns.gr']], $this->namesiloCalls[1]);
    }

    public function test_availability_and_premium_names(): void
    {
        $this->fakeNameSilo();
        $this->namesiloDomains['taken.com'] = ['expires' => '2027-03-15'];

        $this->assertTrue($this->registrar()->available('free.com'));
        $this->assertFalse($this->registrar()->available('taken.com'));
        $this->assertFalse($this->registrar()->available('premium.com'));
    }

    public function test_tld_prices_of_every_tld(): void
    {
        $this->fakeNameSilo();
        $this->namesiloPrices = ['com' => ['registration' => '9.95', 'transfer' => '9.95', 'renew' => '12.95'], 'net' => ['registration' => '11.79', 'renew' => '13.79']];

        $prices = collect($this->registrar()->tldPrices())->keyBy('tld');

        $this->assertSame(['.com', '.net'], $prices->keys()->all());
        $this->assertSame([995, 1295, 995, 'USD', 1], [$prices['.com']->registerCents, $prices['.com']->renewCents, $prices['.com']->transferCents, $prices['.com']->currency, $prices['.com']->years]);
        $this->assertNull($prices['.net']->transferCents);
        $this->assertSame(['.net'], array_map(fn ($p) => $p->tld, $this->registrar()->tldPrices(['.NET'])));
    }

    public function test_an_error_code_shows_its_detail(): void
    {
        Http::fake(['*' => Http::response('<namesilo><reply><code>261</code><detail>Domain is not available</detail></reply></namesilo>')]);

        $this->expectExceptionMessage('NameSilo: Domain is not available');
        $this->registrar()->renew('acme.com', 1);
    }

    public function test_unexpected_response_is_an_error(): void
    {
        Http::fake(['*' => Http::response('<html>maintenance</html>')]);

        $this->expectExceptionMessage('unexpected response');
        $this->registrar()->expiryDate('acme.com');
    }

    public function test_missing_key_never_calls_the_api(): void
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
        $this->fakeNameSilo();
        config(['gaiacp.domains.nameservers' => ['ns1.host.gr', 'ns2.host.gr']]);
        $service = Service::factory()->domain()->create(['domain' => 'acme.com', 'status' => ServiceStatus::Pending, 'start_date' => '2026-10-09', 'next_due_date' => '2026-10-09']);
        $service->domainDetails()->create(['registration_type' => DomainRegistrationType::Register, 'registrar_id' => $this->namesiloRegistrar->id]);

        app(DomainLifecycle::class)->activate($service->refresh());

        $this->assertSame(['getDomainInfo', 'registerDomain', 'getDomainInfo', 'getDomainInfo'], $this->namesiloOperations());
        $this->assertSame(ServiceStatus::Active, $service->refresh()->status);
        $this->assertSame('2027-10-09', $service->domainDetails->expires_at->toDateString());
    }

    public function test_the_epp_code_is_emailed_to_the_administrative_contact(): void
    {
        $this->fakeNameSilo();
        $service = $this->activeDomain();

        $this->assertNull(app(DomainLifecycle::class)->eppCode($service));

        $this->assertSame(['retrieveAuthCode', ['domain' => 'acme.com']], $this->namesiloCalls[0]);
        $this->assertSame('EPP code of acme.com emailed to the registrant by NameSilo', ActivityLog::query()->where('event', 'domain.epp_requested')->sole()->description);
    }

    public function test_create_a_namesilo_account(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(CreateRegistrar::class)
            ->fillForm(['module' => 'namesilo'])
            ->fillForm(['name' => 'NameSilo', 'credentials' => ['api_key' => 'key-1', 'sandbox' => true]])
            ->call('create')
            ->assertHasNoFormErrors();

        $registrar = Registrar::query()->where('module', 'namesilo')->sole();
        $this->assertSame(['api_key' => 'key-1', 'sandbox' => true], $registrar->credentials);
        $this->assertInstanceOf(NameSiloRegistrar::class, app(RegistrarFactory::class)->forRegistrar($registrar));
    }

    public function test_the_admin_changes_the_lock_contacts_and_private_nameservers(): void
    {
        $this->fakeNameSilo();
        $domain = $this->activeDomain();
        $this->actingAs(User::factory()->create());

        Livewire::test(EditService::class, ['record' => $domain->id])
            ->mountAction('registrarLock')
            ->assertSchemaStateSet(['locked' => true])
            ->fillForm(['locked' => false])
            ->callMountedAction()
            ->assertNotified(__('The domain is unlocked'));
        $this->assertFalse($this->namesiloDomains['acme.com']['locked']);
        $this->assertSame('domainUnlock', $this->namesiloOperations()[1]);
        $this->assertSame('Registrar lock off for acme.com', ActivityLog::query()->where('event', 'domain.lock_changed')->value('description'));

        Livewire::test(EditService::class, ['record' => $domain->id])
            ->mountAction('domainContacts')
            ->assertSchemaStateSet(['contacts.registrant.first_name' => 'John', 'contacts.billing.company' => 'Acme Inc'])
            ->fillForm(['contacts.tech.first_name' => 'Tom'])
            ->callMountedAction()
            ->assertHasNoFormErrors();

        // The same details become one new record, Tom's another; contact 7 (maybe used by other domains) is untouched.
        $roles = $this->namesiloDomains['acme.com']['contacts'];
        $this->assertSame($roles['registrant'], $roles['administrative']);
        $this->assertSame($roles['registrant'], $roles['billing']);
        $this->assertNotSame($roles['registrant'], $roles['technical']);
        $this->assertSame('Tom', $this->namesiloContacts[$roles['technical']]['first_name']);
        $this->assertSame('John', $this->namesiloContacts['7']['first_name']);

        Livewire::test(EditService::class, ['record' => $domain->id])
            ->callAction('childNameservers', ['operation' => 'save', 'host' => 'ns1', 'ip' => '192.0.2.10'])
            ->callAction('childNameservers', ['operation' => 'save', 'host' => 'ns1', 'ip' => '192.0.2.20']);
        $this->assertSame(['ns1' => '192.0.2.20'], $this->namesiloHosts);
        $this->assertContains('modifyRegisteredNameServer', $this->namesiloOperations());
    }
}
