<?php

namespace Tests\Feature;

use App\Enums\AutoSetup;
use App\Enums\BillingCycle;
use App\Filament\Resources\Orders\Pages\CreateOrder;
use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Services\Pages\CreateService;
use App\Models\Client;
use App\Models\Product;
use App\Models\User;
use Filament\Forms\Components\Repeater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class ProductPricingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(); // empty WHM answers for package suggestions
        $this->actingAs(User::factory()->create());
    }

    /** "Acme Starter": 80 a year, 160 for two years, 10 setup on the yearly one. */
    private function starter(array $attributes = []): Product
    {
        return Product::factory()->create([
            'name' => 'Acme Starter',
            'prices' => [
                'annually' => ['enabled' => true, 'price_cents' => 8000, 'setup_cents' => 1000],
                'biennially' => ['enabled' => true, 'price_cents' => 16000, 'setup_cents' => 0],
                'monthly' => ['enabled' => false, 'price_cents' => 900, 'setup_cents' => 0],
            ],
            ...$attributes,
        ]);
    }

    public function test_prices_per_cycle(): void
    {
        $product = $this->starter();

        $this->assertSame([BillingCycle::Annually, BillingCycle::Biennially], $product->cycles());
        $this->assertSame(16000, $product->priceFor(BillingCycle::Biennially));
        $this->assertNull($product->priceFor(BillingCycle::Monthly)); // disabled
        $this->assertSame(1000, $product->setupFeeFor(BillingCycle::Annually));
        $this->assertSame(BillingCycle::Annually, $product->defaultCycle());
    }

    public function test_the_product_form_saves_prices_in_cents(): void
    {
        Livewire::test(CreateProduct::class)
            ->fillForm([
                'name' => 'Acme Starter',
                'type' => 'hosting',
                'billing_cycle' => 'annually',
                'prices.annually.enabled' => true,
                'prices.annually.price_cents' => '80',
                'prices.annually.setup_cents' => '10,50',
                'prices.biennially.enabled' => true,
                'prices.biennially.price_cents' => '160.00',
                'hidden' => true,
                'auto_setup' => 'payment',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $product = Product::sole();
        $this->assertSame(8000, $product->priceFor(BillingCycle::Annually));
        $this->assertSame(1050, $product->setupFeeFor(BillingCycle::Annually));
        $this->assertSame(16000, $product->priceFor(BillingCycle::Biennially));
        $this->assertTrue($product->hidden);
        $this->assertSame(AutoSetup::OnPayment, $product->auto_setup);
    }

    public function test_the_default_cycle_needs_a_price(): void
    {
        Livewire::test(CreateProduct::class)
            ->fillForm(['name' => 'X', 'type' => 'maintenance', 'billing_cycle' => 'monthly', 'prices.annually.enabled' => true, 'prices.annually.price_cents' => '10'])
            ->call('create')
            ->assertHasFormErrors(['billing_cycle']);
    }

    public function test_a_new_service_takes_the_price_of_the_picked_cycle(): void
    {
        $product = $this->starter();

        Livewire::test(CreateService::class)
            ->fillForm(['product_id' => $product->id])
            ->assertSchemaStateSet(['billing_cycle' => BillingCycle::Annually, 'price_cents' => '80.00'])
            ->fillForm(['billing_cycle' => 'biennially'])
            ->assertSchemaStateSet(['price_cents' => '160.00']);
    }

    public function test_an_order_item_takes_the_price_and_setup_fee(): void
    {
        $product = $this->starter();
        $undo = Repeater::fake();

        Livewire::test(CreateOrder::class)
            ->fillForm(['client_id' => Client::factory()->create()->id, 'items' => [['type' => 'hosting']]])
            ->fillForm(['items.0.product_id' => $product->id])
            ->assertSchemaStateSet(['items.0.price_cents' => '80.00', 'items.0.first_payment_cents' => '90.00'])
            ->fillForm(['items.0.billing_cycle' => 'biennially'])
            ->assertSchemaStateSet(['items.0.price_cents' => '160.00', 'items.0.first_payment_cents' => null]);

        $undo();
    }

    public function test_hidden_products_are_left_out_of_orders_and_retired_ones_out_of_everything(): void
    {
        $this->starter(['name' => 'Visible']);
        $this->starter(['name' => 'Hidden plan', 'hidden' => true]);
        $this->starter(['name' => 'Retired plan', 'retired' => true]);

        $this->assertSame(['Visible'], Product::query()->orderable()->pluck('name')->all());
        $this->assertSame(['Visible', 'Hidden plan'], Product::query()->selectable()->orderBy('id')->pluck('name')->all());

        $this->get('/admin/settings/products')->assertOk()->assertSee('Visible')->assertDontSee('Retired plan');
    }
}
