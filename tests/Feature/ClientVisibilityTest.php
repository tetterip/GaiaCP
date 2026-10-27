<?php

namespace Tests\Feature;

use App\Enums\ServiceType;
use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Services\Pages\CreateService;
use App\Models\Client;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/** "Show in client area": on by default, copied from the product to its services; Maintenance shows but is never orderable. */
class ClientVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        $this->actingAs(User::factory()->create());
    }

    public function test_maintenance_shows_in_the_client_area_by_default(): void
    {
        $hosting = Product::factory()->create();
        $maintenance = Product::factory()->maintenance()->create();

        $this->assertTrue($hosting->client_visible);
        $this->assertTrue($maintenance->client_visible);

        $client = Client::factory()->create();
        $this->assertTrue(Service::factory()->for($client)->create(['type' => ServiceType::Maintenance, 'product_id' => null])->client_visible);
        $this->assertTrue(Service::factory()->for($client)->create(['type' => ServiceType::Other, 'product_id' => null])->client_visible);
    }

    public function test_a_service_copies_the_switch_from_its_product_and_can_override_it(): void
    {
        $product = Product::factory()->create(['client_visible' => false]);
        $client = Client::factory()->create();

        $this->assertFalse(Service::factory()->for($client)->create(['product_id' => $product->id])->client_visible);
        $this->assertTrue(Service::factory()->for($client)->create(['product_id' => $product->id, 'client_visible' => true])->client_visible);

        $this->assertSame(1, Service::query()->clientVisible()->count());
    }

    public function test_only_visible_orderable_products_can_be_ordered_by_clients(): void
    {
        Product::factory()->create(['name' => 'Starter']);
        Product::factory()->create(['name' => 'Private', 'client_visible' => false]);
        Product::factory()->create(['name' => 'Hidden', 'hidden' => true]);
        Product::factory()->maintenance()->create(['name' => 'Website care', 'client_visible' => true]);
        Product::factory()->create(['name' => 'SSL', 'type' => ServiceType::Other]);

        $this->assertSame(['Starter', 'SSL'], Product::query()->clientOrderable()->orderBy('id')->pluck('name')->all());
    }

    public function test_the_product_form_keeps_the_switch_on_for_maintenance(): void
    {
        Livewire::test(CreateProduct::class)
            ->assertSchemaStateSet(['client_visible' => true])
            ->fillForm(['type' => 'maintenance'])
            ->assertSchemaStateSet(['client_visible' => true])
            ->fillForm([
                'name' => 'Website care',
                'billing_cycle' => 'annually',
                'prices.annually.enabled' => true,
                'prices.annually.price_cents' => '120',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertTrue(Product::sole()->client_visible);
    }

    public function test_the_service_form_fills_the_switch_from_the_product(): void
    {
        $product = Product::factory()->maintenance()->create(['client_visible' => false]);

        Livewire::test(CreateService::class)
            ->fillForm(['product_id' => $product->id])
            ->assertSchemaStateSet(['client_visible' => false])
            ->fillForm(['product_id' => null])
            ->assertSchemaStateSet(['client_visible' => true]);
    }
}
