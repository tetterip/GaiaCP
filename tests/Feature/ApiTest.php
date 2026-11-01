<?php

namespace Tests\Feature;

use App\Api\OpenApi;
use App\Enums\AdminArea;
use App\Enums\BillingCycle;
use App\Enums\InvoiceStatus;
use App\Enums\OrderStatus;
use App\Enums\ServiceStatus;
use App\Enums\ServiceType;
use App\Events\GaiaEvent;
use App\Filament\Clusters\Settings\Pages\ApiKeySettings;
use App\Filament\Pages\ApiDocumentation;
use App\Filament\Resources\ActivityLogs\ActivityLogResource;
use App\Http\Middleware\AuthenticateApiKey;
use App\Models\ActivityLog;
use App\Models\ApiKey;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Registrar;
use App\Models\Service;
use App\Models\User;
use App\Support\LoginSecurity;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Tests\TestCase;

/** The REST API (/api/v1): keys from Settings → API keys, their permissions and IPs, and each area's calls. */
class ApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    /** @param  list<string>|null  $permissions  null = all of them */
    private function key(?array $permissions = null, array $attributes = []): string
    {
        [, $token] = ApiKey::generate($attributes + ['name' => 'Website', 'permissions' => $permissions ?? OpenApi::permissions()]);

        return $token;
    }

    private function api(string $token): static
    {
        return $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json']);
    }

    private function hosting(array $attributes = []): Product
    {
        return Product::factory()->create($attributes + [
            'name' => 'Hosting Business',
            'type' => ServiceType::Hosting,
            'billing_cycle' => 'annually',
            'prices' => [
                'monthly' => ['enabled' => true, 'price_cents' => 800, 'setup_cents' => 0],
                'annually' => ['enabled' => true, 'price_cents' => 9000, 'setup_cents' => 1000],
            ],
        ]);
    }

    public function test_calls_need_an_active_key(): void
    {
        $this->getJson('/api/v1/clients')->assertUnauthorized()->assertJson(['message' => 'Missing, wrong or revoked API key.']);
        $this->api('gcp_wrong')->getJson('/api/v1/clients')->assertUnauthorized();

        $token = $this->key();
        $this->api($token)->getJson('/api/v1')->assertOk()->assertJson(['name' => 'Website'])->assertJsonFragment(['clients.read']);
        $this->withHeaders(['X-API-Key' => $token])->getJson('/api/v1')->assertOk();

        ApiKey::query()->update(['revoked_at' => now()]);
        $this->api($token)->getJson('/api/v1')->assertUnauthorized();
    }

    public function test_wrong_keys_count_as_failed_logins_and_banned_addresses_get_no_answer(): void
    {
        $token = $this->key();

        foreach (range(1, (int) config('gaiacp.login_security.max_attempts')) as $i) {
            $this->api('gcp_guess'.$i)->getJson('/api/v1')->assertUnauthorized();
        }

        $this->assertNotNull(LoginSecurity::banFor('127.0.0.1'));
        $this->api($token)->getJson('/api/v1')->assertForbidden()->assertJson(['message' => 'This address is banned.']);

        // With Login security off, a flood of wrong keys is still slowed down
        config(['gaiacp.login_security.enabled' => false]);
        $this->api($token)->getJson('/api/v1')->assertOk();
        foreach (range(1, AuthenticateApiKey::FAILURES_PER_MINUTE) as $i) {
            $this->api('gcp_more'.$i)->getJson('/api/v1');
        }
        $this->api($token)->getJson('/api/v1')->assertStatus(429);
    }

    public function test_a_record_is_not_found_before_the_key_is_checked(): void
    {
        $this->getJson('/api/v1/clients/999')->assertUnauthorized();
    }

    public function test_the_key_is_kept_only_as_its_hash_and_notes_when_it_was_used(): void
    {
        $token = $this->key();
        $key = ApiKey::query()->sole();

        $this->assertSame(hash('sha256', $token), $key->token_hash);
        $this->assertStringStartsWith($key->token_prefix, $token);
        $this->assertArrayNotHasKey('token_hash', $key->toArray());
        $this->assertNull($key->last_used_at);

        $this->api($token)->getJson('/api/v1', ['REMOTE_ADDR' => '203.0.113.5'])->assertOk();

        $this->assertNotNull($key->refresh()->last_used_at);
        $this->assertSame('203.0.113.5', $key->last_used_ip);
    }

    public function test_a_key_with_allowed_ips_answers_only_from_them(): void
    {
        $token = $this->key(attributes: ['allowed_ips' => ['203.0.113.0/24', '2001:db8::1']]);

        $this->api($token)->getJson('/api/v1')->assertForbidden()->assertJson(['message' => 'This API key is not allowed from 127.0.0.1.']);
        $this->api($token)->withServerVariables(['REMOTE_ADDR' => '203.0.113.77'])->getJson('/api/v1')->assertOk();
        $this->api($token)->withServerVariables(['REMOTE_ADDR' => '2001:db8::1'])->getJson('/api/v1')->assertOk();
    }

    public function test_each_call_needs_its_permission(): void
    {
        $client = Client::factory()->create();
        $token = $this->key(['clients.read']);

        $this->api($token)->getJson('/api/v1/clients')->assertOk();
        $this->api($token)->getJson("/api/v1/clients/{$client->id}")->assertOk();
        $this->api($token)->patchJson("/api/v1/clients/{$client->id}", ['company' => 'X'])->assertForbidden()
            ->assertJson(['message' => 'This API key may not update clients (clients.update).']);
        $this->api($token)->getJson('/api/v1/payments')->assertForbidden();
        $this->api($token)->postJson('/api/v1/payments', [])->assertForbidden();
    }

    public function test_every_route_is_described_and_needs_a_known_permission(): void
    {
        $described = collect(OpenApi::endpoints())->map(fn (array $e) => strtoupper($e['method']).' '.$e['path'])->all();
        $routes = collect(Route::getRoutes()->getRoutes())->filter(fn ($route) => str_starts_with($route->uri(), 'api/v1'));

        $this->assertNotEmpty($routes);

        foreach ($routes as $route) {
            $path = preg_replace('/\{\w+\}/', '{id}', '/'.ltrim(substr($route->uri(), strlen('api/v1')), '/'));
            $method = collect($route->methods())->reject(fn ($m) => $m === 'HEAD')->first();
            $this->assertContains("{$method} {$path}", $described, "{$method} {$path} is not in OpenApi::endpoints()");

            $permission = collect($route->gatherMiddleware())->first(fn ($m) => str_starts_with($m, 'api.key:'));
            $endpoint = collect(OpenApi::endpoints())->first(fn ($e) => strtoupper($e['method']).' '.$e['path'] === "{$method} {$path}");
            $this->assertSame($endpoint['permission'], $permission ? substr($permission, strlen('api.key:')) : null, "{$method} {$path}");
            if ($endpoint['permission']) {
                $this->assertContains($endpoint['permission'], OpenApi::permissions());
            }
        }

        $this->assertCount(count($described), $routes);
    }

    public function test_openapi_json_describes_the_api_at_this_install(): void
    {
        $spec = $this->api($this->key([]))->getJson('/api/v1/openapi.json')->assertOk()->json();

        $this->assertSame('3.1.0', $spec['openapi']);
        $this->assertSame(url('api/v1'), $spec['servers'][0]['url']);
        $this->assertArrayHasKey('/clients/{id}', $spec['paths']);
        $this->assertArrayHasKey('Client', $spec['components']['schemas']);
        $this->assertStringContainsString('clients.read', $spec['paths']['/clients']['get']['description']);
    }

    public function test_clients_are_listed_created_changed_and_deleted_and_logged_with_the_key(): void
    {
        Client::factory()->create(['first_name' => 'Maria', 'last_name' => 'Papa', 'email' => 'maria@example.com']);
        Client::factory()->create(['first_name' => 'Nikos', 'last_name' => 'Kos']);
        $token = $this->key();

        $this->api($token)->getJson('/api/v1/clients?search=papa')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.email', 'maria@example.com')->assertJsonPath('meta.total', 1);
        $this->api($token)->getJson('/api/v1/clients?per_page=1')->assertJsonCount(1, 'data')->assertJsonPath('meta.last_page', 2);

        $this->api($token)->postJson('/api/v1/clients', ['first_name' => 'Eleni'])->assertUnprocessable()->assertJsonValidationErrors(['last_name', 'email']);

        $id = $this->api($token)->postJson('/api/v1/clients', ['first_name' => 'Eleni', 'last_name' => 'Ioannou', 'email' => 'eleni@example.com', 'country' => 'CY'])
            ->assertCreated()->assertJsonPath('data.country', 'CY')->assertJsonPath('data.status', 'active')->json('data.id');

        $this->api($token)->patchJson("/api/v1/clients/{$id}", ['company' => 'Ioannou Ltd'])->assertOk()
            ->assertJsonPath('data.company', 'Ioannou Ltd')->assertJsonPath('data.email', 'eleni@example.com');

        $log = ActivityLog::query()->where('event', 'client.updated')->sole();
        $this->assertSame(ApiKey::query()->sole()->id, $log->api_key_id);
        $this->assertNull($log->user_id);
        $this->assertStringContainsString('company', $log->description);

        $this->api($token)->deleteJson("/api/v1/clients/{$id}")->assertNoContent();
        $this->assertNull(Client::find($id));
        $this->assertTrue(ActivityLog::query()->where('event', 'client.deleted')->exists());
    }

    public function test_a_client_with_services_is_not_deleted(): void
    {
        $service = Service::factory()->create();

        $this->api($this->key())->deleteJson("/api/v1/clients/{$service->client_id}")->assertStatus(409);
        $this->assertNotNull(Client::find($service->client_id));
    }

    public function test_an_order_is_placed_from_the_catalogue_with_its_payment_notice(): void
    {
        $client = Client::factory()->create();
        $hosting = $this->hosting();
        Registrar::factory()->withTlds()->create();
        $token = $this->key();

        $response = $this->api($token)->postJson('/api/v1/orders', [
            'client_id' => $client->id,
            'items' => [
                ['product_id' => $hosting->id, 'billing_cycle' => 'annually', 'domain' => 'shop.gr', 'domain_option' => 'register'],
            ],
            'notes' => 'From the website',
            // Prices are never taken from the call
            'price_cents' => 1,
        ])->assertCreated();

        $order = Order::query()->sole();
        $response->assertJsonPath('data.id', $order->id)->assertJsonPath('data.status', 'pending')->assertJsonCount(2, 'data.items');
        $hostingItem = $order->services()->where('type', ServiceType::Hosting)->sole();
        $this->assertSame(9000, $hostingItem->price_cents);
        $this->assertSame(10000, $hostingItem->first_payment_cents);
        $this->assertSame('shop.gr', $hostingItem->domain);
        $this->assertSame(1, Invoice::query()->where('order_id', $order->id)->count());
        $response->assertJsonPath('data.invoice_ids', [Invoice::query()->sole()->id]);

        $log = ActivityLog::query()->where('event', 'order.placed')->sole();
        $this->assertSame(ApiKey::query()->sole()->id, $log->api_key_id);
    }

    public function test_an_order_of_something_not_orderable_says_why(): void
    {
        $client = Client::factory()->create();
        $hidden = $this->hosting(['client_visible' => false]);

        $this->api($this->key())->postJson('/api/v1/orders', ['client_id' => $client->id, 'items' => [['product_id' => $hidden->id, 'domain' => 'a.gr']]])
            ->assertUnprocessable()->assertJsonValidationErrors('items');
        $this->assertSame(0, Order::query()->count());
    }

    public function test_an_order_is_completed_and_a_pending_one_cancelled(): void
    {
        $client = Client::factory()->create();
        $other = Product::factory()->create(['type' => ServiceType::Other, 'billing_cycle' => 'monthly', 'prices' => ['monthly' => ['enabled' => true, 'price_cents' => 500, 'setup_cents' => 0]]]);
        $token = $this->key();

        $first = $this->api($token)->postJson('/api/v1/orders', ['client_id' => $client->id, 'items' => [['product_id' => $other->id]]])->assertCreated()->json('data.id');
        $this->api($token)->postJson("/api/v1/orders/{$first}/complete")->assertOk()
            ->assertJsonPath('data.status', 'completed')->assertJsonPath('data.items.0.status', 'active');
        $this->api($token)->postJson("/api/v1/orders/{$first}/cancel")->assertStatus(409);

        $second = $this->api($token)->postJson('/api/v1/orders', ['client_id' => $client->id, 'items' => [['product_id' => $other->id]]])->json('data.id');
        $this->api($token)->postJson("/api/v1/orders/{$second}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertSame(OrderStatus::Cancelled, Order::find($second)->status);
        $this->assertSame(InvoiceStatus::Cancelled, Invoice::query()->where('order_id', $second)->sole()->status);
    }

    public function test_a_payment_moves_the_due_date_and_raises_the_same_event_as_the_panel(): void
    {
        Event::fake([GaiaEvent::class]);
        $service = Service::factory()->create(['start_date' => '2025-11-01', 'next_due_date' => '2026-11-01', 'price_cents' => 6000, 'billing_cycle' => 'annually', 'status' => ServiceStatus::Active]);
        $token = $this->key();

        $id = $this->api($token)->postJson('/api/v1/payments', ['service_id' => $service->id, 'method' => 'cash', 'reference' => 'TX-1'])
            ->assertCreated()->assertJsonPath('data.amount_cents', 6000)->assertJsonPath('data.due_date_after', '2027-11-01')->json('data.id');

        $this->assertSame('2027-11-01', $service->refresh()->next_due_date->toDateString());
        Event::assertDispatched(GaiaEvent::class, fn (GaiaEvent $event) => $event->name === 'payment.recorded' && $event->log->api_key_id === ApiKey::query()->sole()->id);

        $this->api($token)->getJson("/api/v1/payments?service_id={$service->id}")->assertJsonCount(1, 'data');

        // Reversed: the due date goes back
        $this->api($token)->deleteJson("/api/v1/payments/{$id}")->assertNoContent();
        $this->assertSame('2026-11-01', $service->refresh()->next_due_date->toDateString());
        $this->assertNull(Payment::find($id));
    }

    public function test_a_payment_notice_is_paid_emailed_and_cancelled(): void
    {
        $client = Client::factory()->create();
        $other = Product::factory()->create(['type' => ServiceType::Other, 'billing_cycle' => 'monthly', 'prices' => ['monthly' => ['enabled' => true, 'price_cents' => 500, 'setup_cents' => 0]]]);
        $token = $this->key();

        $this->api($token)->postJson('/api/v1/orders', ['client_id' => $client->id, 'items' => [['product_id' => $other->id]]])->assertCreated();
        $invoice = Invoice::query()->sole();

        $this->api($token)->getJson('/api/v1/invoices?status=unpaid')->assertJsonCount(1, 'data')->assertJsonPath('data.0.number', $invoice->number);
        $this->api($token)->postJson("/api/v1/invoices/{$invoice->id}/send")->assertOk();

        $this->api($token)->postJson("/api/v1/invoices/{$invoice->id}/pay", ['method' => 'cash'])->assertCreated()
            ->assertJsonPath('data.status', 'paid')->assertJsonCount(1, 'payments');
        $this->api($token)->postJson("/api/v1/invoices/{$invoice->id}/pay")->assertStatus(409);
        $this->api($token)->postJson("/api/v1/invoices/{$invoice->id}/cancel")->assertStatus(409);
    }

    public function test_paying_a_notice_needs_payments_create(): void
    {
        // Refused before the notice is looked up
        $this->api($this->key(['invoices.read', 'invoices.update']))->postJson('/api/v1/invoices/999/pay')->assertForbidden();
    }

    public function test_services_are_read_changed_suspended_and_terminated(): void
    {
        $service = Service::factory()->create(['type' => ServiceType::Other, 'status' => ServiceStatus::Active, 'password' => 'secret-pass']);
        $token = $this->key();

        $this->api($token)->getJson("/api/v1/services/{$service->id}")->assertOk()->assertJsonMissingPath('data.password')
            ->assertJsonPath('data.status', 'active');
        $this->assertStringNotContainsString('secret-pass', $this->api($token)->getJson('/api/v1/services')->getContent());

        $this->api($token)->patchJson("/api/v1/services/{$service->id}", ['notes' => 'VIP', 'auto_terminate' => true, 'price_cents' => 1])->assertOk()
            ->assertJsonPath('data.notes', 'VIP')->assertJsonPath('data.auto_terminate', true);
        $this->assertNotSame(1, $service->refresh()->price_cents);

        $this->api($token)->postJson("/api/v1/services/{$service->id}/suspend", ['reason' => 'Abuse'])->assertOk()->assertJsonPath('data.status', 'suspended');
        $this->api($token)->postJson("/api/v1/services/{$service->id}/unsuspend")->assertOk()->assertJsonPath('data.status', 'active');
        $this->api($token)->postJson("/api/v1/services/{$service->id}/terminate")->assertOk()->assertJsonPath('data.status', 'terminated');
        $this->api($token)->postJson("/api/v1/services/{$service->id}/suspend")->assertStatus(409);

        $this->assertSame(['service.updated', 'service.suspended', 'service.unsuspended', 'service.terminated'], ActivityLog::query()
            ->where('service_id', $service->id)->whereNotNull('api_key_id')->orderBy('id')->pluck('event')->all());
    }

    public function test_products_are_created_changed_and_deleted(): void
    {
        $token = $this->key();

        $this->api($token)->postJson('/api/v1/products', ['name' => 'X', 'type' => 'hosting', 'prices' => ['weekly' => ['price_cents' => 1]]])
            ->assertUnprocessable()->assertJsonValidationErrors('prices');

        $id = $this->api($token)->postJson('/api/v1/products', [
            'name' => 'Email Pro', 'type' => 'other', 'prices' => ['monthly' => ['price_cents' => 300], 'annually' => ['price_cents' => 3000, 'setup_cents' => 500]],
        ])->assertCreated()->assertJsonPath('data.billing_cycle', 'monthly')->assertJsonPath('data.prices.annually.setup_cents', 500)->json('data.id');

        $product = Product::find($id);
        $this->assertSame(3000, $product->priceFor(BillingCycle::Annually));

        $this->api($token)->patchJson("/api/v1/products/{$id}", ['hidden' => true, 'type' => 'hosting'])->assertUnprocessable();
        $this->api($token)->patchJson("/api/v1/products/{$id}", ['hidden' => true, 'billing_cycle' => 'annually'])->assertOk()
            ->assertJsonPath('data.hidden', true)->assertJsonPath('data.billing_cycle', 'annually');

        Service::factory()->create(['product_id' => $id]);
        $this->api($token)->deleteJson("/api/v1/products/{$id}")->assertStatus(409);

        $unused = Product::factory()->create();
        $this->api($token)->deleteJson("/api/v1/products/{$unused->id}")->assertNoContent();
        $this->assertTrue(ActivityLog::query()->where('event', 'product.deleted')->exists());
    }

    public function test_too_many_calls_are_refused(): void
    {
        config(['gaiacp.api.per_minute' => 2]);
        $token = $this->key();

        $this->api($token)->getJson('/api/v1')->assertOk();
        $this->api($token)->getJson('/api/v1')->assertOk();
        $this->api($token)->getJson('/api/v1')->assertTooManyRequests();
    }

    public function test_the_key_form_ticks_all_read_only_or_no_permissions_at_once(): void
    {
        $this->actingAs(User::factory()->create());

        $all = collect(ApiKey::PERMISSIONS)->map(fn (array $actions) => $actions)->all();
        $read = collect(ApiKey::PERMISSIONS)->map(fn (array $actions) => array_values(array_intersect($actions, ['read'])))->all();
        $none = collect(ApiKey::PERMISSIONS)->map(fn () => [])->all();

        Livewire::test(ApiKeySettings::class)
            ->mountAction('create')
            ->callAction(TestAction::make('permissions_all')->schemaComponent('permissionActions'))
            ->assertSchemaStateSet(['permissions' => $all], 'mountedActionSchema0')
            ->callAction(TestAction::make('permissions_read')->schemaComponent('permissionActions'))
            ->assertSchemaStateSet(['permissions' => $read], 'mountedActionSchema0')
            ->callAction(TestAction::make('permissions_none')->schemaComponent('permissionActions'))
            ->assertSchemaStateSet(['permissions' => $none], 'mountedActionSchema0')
            ->callAction(TestAction::make('permissions_all')->schemaComponent('permissionActions'))
            ->fillForm(['name' => 'Everything'])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertSame(OpenApi::permissions(), ApiKey::query()->sole()->permissions);
    }

    public function test_a_super_admin_makes_a_key_that_is_shown_once(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(ApiKeySettings::class)
            ->callAction('create', [
                'name' => 'Website',
                'permissions' => ['clients' => ['read', 'create'], 'payments' => ['create']],
                'allowed_ips' => "203.0.113.10\n2001:db8::/32",
            ])
            ->assertHasNoActionErrors()
            ->assertNotified(__('Copy the API key now'));

        $key = ApiKey::query()->sole();
        $this->assertSame(['clients.read', 'clients.create', 'payments.create'], $key->permissions);
        $this->assertSame(['203.0.113.10', '2001:db8::/32'], $key->allowed_ips);
        $this->assertTrue(ActivityLog::query()->where('event', 'api_key.created')->exists());

        Livewire::test(ApiKeySettings::class)
            ->assertCanSeeTableRecords([$key])
            ->assertSee($key->token_prefix)
            ->assertDontSee($key->token_hash)
            ->callAction(TestAction::make('edit')->table($key), ['name' => 'Shop', 'permissions' => ['clients' => [], 'payments' => [], 'orders' => ['read']], 'allowed_ips' => ''])
            ->assertHasNoActionErrors()
            ->callAction(TestAction::make('revoke')->table($key));

        $key->refresh();
        $this->assertSame('Shop', $key->name);
        $this->assertSame(['orders.read'], $key->permissions);
        $this->assertNull($key->allowed_ips);
        $this->assertTrue($key->revoked());
    }

    public function test_a_wrong_ip_is_refused_on_the_form(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(ApiKeySettings::class)
            ->callAction('create', ['name' => 'Website', 'allowed_ips' => '203.0.113.300'])
            ->assertHasActionErrors(['allowed_ips']);
    }

    public function test_only_super_admins_manage_keys_and_read_the_documentation(): void
    {
        User::factory()->create();
        $admin = User::factory()->limitedTo(AdminArea::cases())->create();

        $this->actingAs($admin)->get(ApiKeySettings::getUrl())->assertForbidden();
        $this->actingAs($admin)->get(ApiDocumentation::getUrl())->assertForbidden();

        $this->actingAs(User::query()->first())->get(ApiDocumentation::getUrl())->assertOk()
            ->assertSee('/orders/{id}/complete')->assertSee('payments.create');
    }

    public function test_the_activity_log_names_the_key(): void
    {
        $this->actingAs(User::factory()->create());
        $token = $this->key();
        $this->actingAs(User::query()->first());
        auth()->logout();

        $this->api($token)->postJson('/api/v1/clients', ['first_name' => 'A', 'last_name' => 'B', 'email' => 'ab@example.com'])->assertCreated();

        $this->actingAs(User::query()->first())
            ->get(ActivityLogResource::getUrl())
            ->assertOk()
            ->assertSee(__('API: :key', ['key' => 'Website']));
    }
}
