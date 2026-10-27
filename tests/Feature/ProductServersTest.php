<?php

namespace Tests\Feature;

use App\Actions\ClientOrder;
use App\Actions\SetUpService;
use App\Enums\ServiceStatus;
use App\Enums\ServiceType;
use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Services\Pages\CreateService;
use App\Models\Product;
use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use App\Provisioning\ProvisioningException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\FakesWhm;
use Tests\TestCase;

/** A hosting product on several servers with the same package: a new account goes to the least full one. */
class ProductServersTest extends TestCase
{
    use FakesWhm, RefreshDatabase;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeWhm();
        $this->actingAs(User::factory()->create());
        $this->product = Product::factory()->priced(6000)->create(['name' => 'Hosting Basic', 'package' => 'basic']);
    }

    /** A server holding $used accounts (active ones, plus $pending pending). */
    private function server(string $name, ?int $max, int $used = 0, int $pending = 0, bool $active = true): Server
    {
        $server = Server::factory()->create(['name' => $name, 'max_accounts' => $max, 'active' => $active]);
        Service::factory()->count($used)->create(['type' => ServiceType::Hosting, 'server_id' => $server->id, 'status' => ServiceStatus::Active]);
        Service::factory()->count($pending)->create(['type' => ServiceType::Hosting, 'server_id' => $server->id, 'status' => ServiceStatus::Pending]);
        $this->product->servers()->attach($server);

        return $server;
    }

    public function test_the_least_full_server_is_picked(): void
    {
        $this->server('Server 1', 10, used: 8);
        $emptiest = $this->server('Server 2', 100, used: 30);
        $this->server('Server 3', 4, used: 1, pending: 1);

        $this->assertTrue($emptiest->is($this->product->pickServer()));
    }

    public function test_full_and_inactive_servers_are_skipped(): void
    {
        $this->server('Full', 2, used: 1, pending: 1);
        $this->server('Off', 100, active: false);
        $room = $this->server('Room', 10, used: 9);

        $this->assertTrue($room->is($this->product->pickServer()));

        Service::factory()->create(['type' => ServiceType::Hosting, 'server_id' => $room->id, 'status' => ServiceStatus::Active]);
        $this->assertNull($this->product->pickServer());
    }

    public function test_a_server_without_a_maximum_counts_as_empty_and_ties_go_to_fewer_accounts(): void
    {
        $this->server('Limited', 100, used: 1);
        $this->server('Busy', null, used: 5);
        $quiet = $this->server('Quiet', null, used: 2);

        $this->assertTrue($quiet->is($this->product->pickServer()));
    }

    public function test_the_product_form_saves_its_servers(): void
    {
        $one = Server::factory()->create();
        $two = Server::factory()->create();

        Livewire::test(CreateProduct::class)
            ->fillForm([
                'name' => 'Hosting Pro',
                'type' => 'hosting',
                'servers' => [$one->id, $two->id],
                'package' => 'pro',
                'billing_cycle' => 'annually',
                'prices.annually.enabled' => true,
                'prices.annually.price_cents' => '120',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertEqualsCanonicalizing([$one->id, $two->id], Product::query()->where('name', 'Hosting Pro')->sole()->servers()->pluck('servers.id')->all());
    }

    public function test_a_new_service_fills_in_the_picked_server(): void
    {
        $this->server('Server 1', 10, used: 9);
        $picked = $this->server('Server 2', 10, used: 1);

        Livewire::test(CreateService::class)
            ->fillForm(['product_id' => $this->product->id])
            ->assertSchemaStateSet(['server_id' => $picked->id]);
    }

    public function test_a_client_order_goes_to_the_picked_server(): void
    {
        $this->server('Server 1', 10, used: 9);
        $picked = $this->server('Server 2', 10, used: 1);

        $items = app(ClientOrder::class)->items(['product' => $this->product->id, 'domain' => 'acme.gr', 'domain_option' => ClientOrder::OWN, 'billing_cycle' => 'annually']);

        $this->assertSame($picked->id, $items[0]['server_id']);
    }

    public function test_set_up_picks_a_server_for_an_account_ordered_while_every_one_was_full(): void
    {
        $server = $this->server('Server 1', 1, used: 1);
        $service = Service::factory()->create(['type' => ServiceType::Hosting, 'product_id' => $this->product->id, 'server_id' => null,
            'domain' => 'acme.gr', 'package' => 'basic', 'status' => ServiceStatus::Pending]);

        try {
            app(SetUpService::class)->handle($service);
            $this->fail('Set up should refuse while every server is full.');
        } catch (ProvisioningException $e) {
            $this->assertStringContainsString('Every server of this product is full', $e->getMessage());
        }

        $server->update(['max_accounts' => 5]);
        app(SetUpService::class)->handle($service->refresh());

        $this->assertSame($server->id, $service->refresh()->server_id);
        $this->assertSame(ServiceStatus::Active, $service->status);
    }
}
