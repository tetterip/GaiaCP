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
use App\Provisioning\ManagesChildNameservers;
use App\Provisioning\ProvisioningException;
use App\Provisioning\RegistrarFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Plugins\GoDaddy\GoDaddyClient;
use Plugins\GoDaddy\GoDaddyRegistrar;
use Tests\FakesGoDaddy;
use Tests\TestCase;

class GoDaddyRegistrarTest extends TestCase
{
    use FakesGoDaddy, RefreshDatabase;

    /** @return GoDaddyRegistrar */
    private function registrar(array $credentials = []): DomainRegistrar
    {
        return app(RegistrarFactory::class)->make('godaddy', [...['api_key' => 'key1', 'api_secret' => 'secret'], ...$credentials]);
    }

    private function holds(string $domain = 'acme.com'): void
    {
        $contact = ['nameFirst' => 'John', 'nameLast' => 'Smith', 'organization' => 'Acme Inc', 'email' => 'john@acme.com', 'phone' => '+1.6175551234',
            'addressMailing' => ['address1' => '1 Main St', 'city' => 'Boston', 'state' => 'MA', 'postalCode' => '02101', 'country' => 'US']];
        $this->godaddyDomains[$domain] = ['domain' => $domain, 'expires' => '2027-03-15T23:59:59.000Z', 'locked' => true, 'nameServers' => ['NS1.Host.gr', 'ns2.host.gr'],
            'authCode' => 'Gd#Code1', 'contactRegistrant' => $contact, 'contactAdmin' => $contact, 'contactTech' => $contact, 'contactBilling' => $contact];
    }

    public function test_reads_the_domain_once_with_the_sso_key(): void
    {
        $this->fakeGoDaddy();
        $this->holds();
        $registrar = $this->registrar();

        $this->assertSame('2027-03-15', $registrar->expiryDate('Acme.com')->toDateString());
        $this->assertSame(['ns1.host.gr', 'ns2.host.gr'], $registrar->nameservers('acme.com'));
        $this->assertTrue($registrar->locked('acme.com'));
        $this->assertSame('Gd#Code1', $registrar->eppCode('acme.com'));

        $this->assertSame(['GET v1/domains/acme.com'], $this->godaddyCallNames());
        $this->assertNotInstanceOf(ManagesChildNameservers::class, $registrar);
    }

    public function test_test_mode_and_shopper_id(): void
    {
        $this->fakeGoDaddy();
        $this->holds();

        $this->registrar(['test_mode' => true, 'shopper_id' => '12345'])->expiryDate('acme.com');

        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), GoDaddyClient::TEST_URL.'/v1/domains/acme.com') && $request->header('X-Shopper-Id') === ['12345']);
    }

    public function test_register_accepts_the_tlds_agreements_and_sends_the_client_as_all_contacts(): void
    {
        $this->travelTo('2026-11-12 10:00');
        $this->fakeGoDaddy();
        $client = Client::factory()->create([
            'first_name' => 'Νίκος', 'last_name' => 'Παπαδόπουλος', 'company' => null, 'email' => 'nikos@example.gr', 'phone' => '210 123 4567',
            'address1' => 'Ερμού 1', 'address2' => null, 'city' => 'Αθήνα', 'state' => 'Αττική', 'postcode' => '10563', 'country' => 'gr',
        ]);

        $this->registrar()->register('acme.com', 2, $client, ['ns1.host.gr', 'ns2.host.gr']);

        $this->assertSame(['GET v1/domains/agreements', ['tlds' => 'com', 'privacy' => 'false']], $this->godaddyCalls[0]);
        [$call, $body] = $this->godaddyCalls[1];
        $this->assertSame('POST v1/domains/purchase', $call);
        $this->assertSame(['acme.com', 2, false, false, ['ns1.host.gr', 'ns2.host.gr']], [$body['domain'], $body['period'], $body['privacy'], $body['renewAuto'], $body['nameServers']]);
        $this->assertSame(['DNRA'], $body['consent']['agreementKeys']);
        $this->assertSame(now()->utc()->format('Y-m-d\TH:i:s\Z'), $body['consent']['agreedAt']);
        $this->assertSame([
            'nameFirst' => 'Νίκος', 'nameLast' => 'Παπαδόπουλος', 'email' => 'nikos@example.gr', 'phone' => '+30.2101234567',
            'addressMailing' => ['address1' => 'Ερμού 1', 'city' => 'Αθήνα', 'state' => 'Αττική', 'postalCode' => '10563', 'country' => 'GR'],
        ], $body['contactBilling']);
    }

    public function test_transfer_keeps_the_epp_code_and_keys_out_of_the_module_log(): void
    {
        config(['gaiacp.debug.module_log' => true]);
        $this->fakeGoDaddy();

        $this->registrar()->transfer('acme.com', 'Auth&Xq9z', Client::factory()->create());

        [$call, $body] = $this->godaddyCalls[1];
        $this->assertSame('POST v1/domains/acme.com/transfer', $call);
        $this->assertSame(['Auth&Xq9z', 1, ['DNRA']], [$body['authCode'], $body['period'], $body['consent']['agreementKeys']]);

        $logs = ModuleLog::query()->where('module', 'godaddy')->get();
        $this->assertContains('POST /v1/domains/{domain}/transfer', $logs->pluck('action')->all());
        $text = $logs->map(fn ($log) => $log->url.$log->request.$log->response)->implode('');
        $this->assertStringNotContainsString('Xq9z', $text);
        $this->assertStringNotContainsString('secret', $text);
    }

    public function test_renews_and_reads_the_new_expiry(): void
    {
        $this->fakeGoDaddy();
        $this->holds();
        $registrar = $this->registrar();

        $registrar->renew('acme.com', 2);

        $this->assertSame(['POST v1/domains/acme.com/renew', ['period' => 2]], $this->godaddyCalls[0]);
        $this->assertSame('2029-03-15', $registrar->expiryDate('acme.com')->toDateString());
    }

    public function test_saves_nameservers_and_the_lock(): void
    {
        $this->fakeGoDaddy();
        $this->holds();

        $this->registrar()->setNameservers('acme.com', ['a.ns.gr', 'b.ns.gr']);
        $this->registrar()->setLocked('acme.com', false);

        $this->assertSame([['PATCH v1/domains/acme.com', ['nameServers' => ['a.ns.gr', 'b.ns.gr']]], ['PATCH v1/domains/acme.com', ['locked' => false]]], $this->godaddyCalls);
    }

    public function test_availability(): void
    {
        $this->fakeGoDaddy();
        $this->holds('taken.com');

        $this->assertTrue($this->registrar()->available('free.com'));
        $this->assertFalse($this->registrar()->available('taken.com'));
    }

    public function test_errors_show_the_message_and_fields(): void
    {
        Http::fake(['*' => Http::response(['code' => 'INVALID_BODY', 'message' => 'Request body doesn\'t fulfill schema', 'fields' => [['path' => 'period', 'message' => 'must be at least 1']]], 422)]);

        $this->expectExceptionMessage("GoDaddy: Request body doesn't fulfill schema (period: must be at least 1)");
        $this->registrar()->renew('acme.com', 1);
    }

    public function test_an_unknown_domain_is_an_error(): void
    {
        $this->fakeGoDaddy();

        $this->expectExceptionMessage('GoDaddy: The given domain is not registered');
        $this->registrar()->expiryDate('acme.com');
    }

    public function test_missing_credentials_never_call_the_api(): void
    {
        $registrar = $this->registrar(['api_secret' => '']);

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
        $this->fakeGoDaddy();
        config(['gaiacp.domains.nameservers' => ['ns1.host.gr', 'ns2.host.gr']]);
        $service = Service::factory()->domain()->create(['domain' => 'acme.com', 'status' => ServiceStatus::Pending, 'start_date' => '2026-10-09', 'next_due_date' => '2026-10-09']);
        $service->domainDetails()->create(['registration_type' => DomainRegistrationType::Register, 'registrar_id' => $this->godaddyRegistrar->id]);

        app(DomainLifecycle::class)->activate($service->refresh());

        $this->assertSame(ServiceStatus::Active, $service->refresh()->status);
        $this->assertSame('2027-10-09', $service->domainDetails->expires_at->toDateString());
    }

    public function test_create_a_godaddy_account(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(CreateRegistrar::class)
            ->fillForm(['module' => 'godaddy'])
            ->fillForm(['name' => 'GoDaddy', 'credentials' => ['api_key' => 'k', 'api_secret' => 's', 'shopper_id' => '', 'test_mode' => true]])
            ->call('create')
            ->assertHasNoFormErrors();

        $registrar = Registrar::query()->where('module', 'godaddy')->sole();
        $this->assertSame(['k', 's', true], [$registrar->credentials['api_key'], $registrar->credentials['api_secret'], $registrar->credentials['test_mode']]);
        $this->assertInstanceOf(GoDaddyRegistrar::class, app(RegistrarFactory::class)->forRegistrar($registrar));
    }

    public function test_the_admin_changes_the_contacts(): void
    {
        $this->fakeGoDaddy();
        $service = Service::factory()->domain()->create(['domain' => 'acme.com', 'next_due_date' => '2027-03-15']);
        $service->domainDetails()->create(['registration_type' => DomainRegistrationType::Register, 'registrar_id' => $this->godaddyRegistrar->id]);
        $this->holds();
        $this->actingAs(User::factory()->create());

        Livewire::test(EditService::class, ['record' => $service->id])
            ->mountAction('domainContacts')
            ->assertSchemaStateSet(['contacts.registrant.first_name' => 'John', 'contacts.billing.company' => 'Acme Inc'])
            ->fillForm(['contacts.tech.first_name' => 'Tom'])
            ->callMountedAction()
            ->assertHasNoFormErrors();

        $this->assertSame('Tom', $this->godaddyDomains['acme.com']['contactTech']['nameFirst']);
        $this->assertSame('+1.6175551234', $this->godaddyDomains['acme.com']['contactRegistrant']['phone']);
        $this->assertContains('PATCH v1/domains/acme.com/contacts', $this->godaddyCallNames());
    }
}
