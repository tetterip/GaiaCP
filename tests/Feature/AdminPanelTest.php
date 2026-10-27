<?php

namespace Tests\Feature;

use App\Enums\BillingCycle;
use App\Enums\ServiceStatus;
use App\Enums\ServiceType;
use App\Filament\Resources\Clients\Pages\EditClient;
use App\Filament\Resources\Servers\Pages\CreateServer;
use App\Filament\Resources\Servers\Pages\EditServer;
use App\Filament\Resources\Services\Pages\CreateService;
use App\Filament\Resources\Services\Pages\EditService;
use App\Filament\Resources\Services\RelationManagers\PaymentsRelationManager;
use App\Models\Client;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class AdminPanelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-08 10:00');
        Http::fake(); // empty WHM answers: package suggestions just come back empty
    }

    public function test_guests_are_sent_to_the_login_page(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
        $this->get('/admin/login')->assertOk();
    }

    public function test_the_login_page_says_powered_by_gaiacp_and_other_pages_show_the_copyright(): void
    {
        config(['gaiacp.company.name' => 'Acme Hosting']);

        $this->get('/admin/login')->assertOk()
            ->assertSeeInOrder(['Powered by', 'GaiaCP'])
            ->assertDontSee('All rights reserved.');

        $this->actingAs(User::factory()->create());
        $this->get('/admin')->assertOk()
            ->assertSee('© 2026 Acme Hosting. All rights reserved.')
            ->assertSeeInOrder(['Powered by', 'GaiaCP']);
    }

    public function test_every_page_renders(): void
    {
        $this->actingAs(User::factory()->create());
        $service = Service::factory()->create();
        Payment::factory()->for($service)->create();

        foreach ([
            '/admin',
            '/admin/clients', '/admin/clients/create', "/admin/clients/{$service->client_id}/edit",
            '/admin/settings/servers', '/admin/settings/servers/create', "/admin/settings/servers/{$service->server_id}/edit",
            '/admin/settings/products', '/admin/settings/products/create', '/admin/settings/products/'.Product::factory()->create()->id.'/edit',
            '/admin/services', '/admin/services/create', "/admin/services/{$service->id}/edit",
            '/admin/payments', '/admin/activity-logs',
        ] as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_create_hosting_service_stores_price_in_cents(): void
    {
        $this->actingAs(User::factory()->create());
        $client = Client::factory()->create();
        $server = Server::factory()->create();

        Livewire::test(CreateService::class)
            ->fillForm([
                'client_id' => $client->id,
                'type' => ServiceType::Hosting,
                'label' => 'Web hosting — Basic',
                'domain' => 'example.gr',
                'server_id' => $server->id,
                'username' => 'example',
                'package' => 'basic',
                'price_cents' => '59,90',
                'billing_cycle' => BillingCycle::Annually,
                'start_date' => '2026-10-08',
                'next_due_date' => '2026-10-08',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $service = Service::sole();
        $this->assertSame(5990, $service->price_cents);
        $this->assertSame('EUR', $service->currency);
        $this->assertSame($server->id, $service->server_id);
    }

    public function test_hosting_service_requires_a_server_and_domain_requires_a_domain(): void
    {
        $this->actingAs(User::factory()->create());
        $client = Client::factory()->create();

        Livewire::test(CreateService::class)
            ->fillForm(['client_id' => $client->id, 'type' => ServiceType::Hosting, 'label' => 'x', 'price_cents' => '10', 'server_id' => null])
            ->call('create')
            ->assertHasFormErrors(['server_id' => 'required']);

        Livewire::test(CreateService::class)
            ->fillForm(['client_id' => $client->id, 'type' => ServiceType::Domain, 'label' => 'x', 'price_cents' => '10', 'domain' => null])
            ->call('create')
            ->assertHasFormErrors(['domain' => 'required']);
    }

    public function test_the_service_form_asks_the_type_first_and_offers_only_products_of_that_type(): void
    {
        $this->actingAs(User::factory()->create());
        $hosting = Product::factory()->create(['name' => 'Hosting Basic']);
        $domain = Product::factory()->create(['name' => 'Domain', 'type' => ServiceType::Domain, 'package' => null]);
        $service = Service::factory()->domain()->create(['domain' => 'acme.gr', 'product_id' => $domain->id]);

        $fields = array_keys(array_filter(
            Livewire::test(EditService::class, ['record' => $service->id])->instance()->form->getFlatFields(withHidden: true),
            fn ($field, string $path) => in_array($path, ['type', 'product_id'], true),
            ARRAY_FILTER_USE_BOTH,
        ));
        $this->assertSame(['type', 'product_id'], $fields);

        Livewire::test(EditService::class, ['record' => $service->id])
            ->assertFormFieldExists('product_id', fn ($field) => array_keys($field->getOptions()) === [$domain->id])
            ->fillForm(['type' => ServiceType::Hosting])
            ->assertSchemaStateSet(['product_id' => null])
            ->assertFormFieldExists('product_id', fn ($field) => array_keys($field->getOptions()) === [$hosting->id]);
    }

    public function test_the_type_is_fixed_once_the_service_is_set_up(): void
    {
        $this->actingAs(User::factory()->create());
        Product::factory()->create(['name' => 'Hosting Basic']);
        $domain = Product::factory()->create(['name' => 'Domain', 'type' => ServiceType::Domain, 'package' => null]);
        $service = Service::factory()->domain()->create(['domain' => 'acme.gr', 'product_id' => $domain->id]);

        Livewire::test(EditService::class, ['record' => $service->id])
            ->assertFormFieldDisabled('type')
            ->assertFormFieldExists('product_id', fn ($field) => array_keys($field->getOptions()) === [$domain->id])
            ->fillForm(['type' => ServiceType::Hosting])
            ->call('save');

        $this->assertSame(ServiceType::Domain, $service->fresh()->type);

        $service->update(['status' => ServiceStatus::Pending]);
        Livewire::test(EditService::class, ['record' => $service->id])->assertFormFieldEnabled('type');
        Livewire::test(CreateService::class)->assertFormFieldEnabled('type');
    }

    public function test_invalid_price_is_rejected(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(CreateService::class)
            ->fillForm(['price_cents' => '12.345'])
            ->call('create')
            ->assertHasFormErrors(['price_cents']);
    }

    public function test_record_payment_action_advances_due_date(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $service = Service::factory()->create(['start_date' => '2025-10-20', 'next_due_date' => '2026-10-20', 'price_cents' => 6000]);

        Livewire::test(EditService::class, ['record' => $service->id])
            ->callAction('recordPayment', data: ['amount' => '60.00', 'paid_on' => '2026-10-08', 'method' => 'bank_transfer', 'reference' => 'TX-9'])
            ->assertHasNoActionErrors()
            ->assertSchemaStateSet(['next_due_date' => '2027-10-20']);

        $payment = Payment::sole();
        $this->assertSame(6000, $payment->amount_cents);
        $this->assertSame($user->id, $payment->recorded_by);
        $this->assertSame('2027-10-20', $service->fresh()->next_due_date->toDateString());
    }

    public function test_a_service_paid_ahead_offers_only_an_early_payment(): void
    {
        $this->actingAs(User::factory()->create());
        // Paid until next year: nothing owed, no notice.
        $service = Service::factory()->create(['start_date' => '2026-10-01', 'next_due_date' => '2027-10-01', 'price_cents' => 6000]);

        Livewire::test(EditService::class, ['record' => $service->id])
            ->assertActionHidden('recordPayment')
            ->assertActionVisible('recordEarlyPayment')
            ->callAction('recordEarlyPayment', data: ['amount' => '60.00', 'paid_on' => '2026-10-08', 'method' => 'cash'])
            ->assertHasNoActionErrors();

        $this->assertSame('2028-10-01', $service->fresh()->next_due_date->toDateString());

        // Its due date within the days a notice is issued ahead: owed now.
        $service->update(['next_due_date' => '2026-10-20']);
        Livewire::test(EditService::class, ['record' => $service->id])
            ->assertActionVisible('recordPayment')
            ->assertActionHidden('recordEarlyPayment');
    }

    public function test_reverse_payment_from_the_service_page(): void
    {
        $this->actingAs(User::factory()->create());
        $service = Service::factory()->create(['start_date' => '2025-10-20', 'next_due_date' => '2026-10-20']);

        Livewire::test(EditService::class, ['record' => $service->id])
            ->callAction('recordPayment', data: ['amount' => '60', 'paid_on' => '2026-10-08', 'method' => 'cash']);

        Livewire::test(PaymentsRelationManager::class, ['ownerRecord' => $service->fresh(), 'pageClass' => EditService::class])
            ->callAction(TestAction::make('reverse')->table(Payment::sole()));

        $this->assertSame(0, Payment::count());
        $this->assertSame('2026-10-20', $service->fresh()->next_due_date->toDateString());
    }

    public function test_server_token_is_encrypted_and_kept_when_left_blank(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(CreateServer::class)
            ->fillForm(['name' => 'WHM 1', 'type' => 'cpanel', 'hostname' => 'whm.example.com', 'port' => 2087, 'username' => 'root', 'api_token' => 'secret-token'])
            ->call('create')
            ->assertHasNoFormErrors();

        $server = Server::sole();
        $this->assertSame('secret-token', $server->api_token);
        $this->assertStringNotContainsString('secret-token', (string) $server->getRawOriginal('api_token'));

        Livewire::test(EditServer::class, ['record' => $server->id])
            ->assertSchemaStateSet(['api_token' => null])
            ->fillForm(['name' => 'WHM one'])
            ->call('save')
            ->assertHasNoFormErrors();

        $server->refresh();
        $this->assertSame('WHM one', $server->name);
        $this->assertSame('secret-token', $server->api_token);
    }

    public function test_plesk_server_with_secret_key_and_fixed_ip(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(CreateServer::class)
            ->fillForm(['name' => 'Plesk 1', 'type' => 'plesk', 'hostname' => 'plesk.example.com', 'port' => 8443, 'username' => '', 'api_token' => 'secret-key', 'shared_ip' => '203.0.113.5'])
            ->call('create')
            ->assertHasNoFormErrors();

        $server = Server::sole();
        $this->assertNull($server->username);
        $this->assertSame('203.0.113.5', $server->shared_ip);

        Livewire::test(CreateServer::class)
            ->fillForm(['name' => 'WHM', 'type' => 'cpanel', 'hostname' => 'whm.example.com', 'port' => 2087, 'username' => ''])
            ->call('create')
            ->assertHasFormErrors(['username' => 'required']);
    }

    public function test_clients_with_services_cannot_be_deleted(): void
    {
        $this->actingAs(User::factory()->create());
        $service = Service::factory()->create();
        $lonely = Client::factory()->create();

        Livewire::test(EditClient::class, ['record' => $service->client_id])->assertActionHidden(DeleteAction::class);
        Livewire::test(EditClient::class, ['record' => $lonely->id])->assertActionVisible(DeleteAction::class);
    }
}
