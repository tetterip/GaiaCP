<?php

namespace Tests\Feature;

use App\Actions\CancelOrder;
use App\Actions\ClientOrder;
use App\Actions\PlaceOrder;
use App\Api\OpenApi;
use App\Enums\BillingCycle;
use App\Enums\ServiceStatus;
use App\Enums\ServiceType;
use App\Enums\UserRole;
use App\Filament\Client\Pages\Cart;
use App\Filament\Client\Pages\Order as OrderPage;
use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Models\ApiKey;
use App\Models\Client;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\Service;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/** A product's Quantity available (stock) and Client limit. */
class ProductLimitsTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $this->client = Client::factory()->create();
        $this->user = User::factory()->create(['role' => UserRole::Client, 'client_id' => $this->client->id, 'email' => $this->client->email]);
    }

    private function product(array $attributes = []): Product
    {
        return Product::factory()->priced(1000, BillingCycle::Monthly)->create([
            'name' => 'Dedicated server',
            'type' => ServiceType::Other,
            'package' => null,
            ...$attributes,
        ]);
    }

    private function asClient(): void
    {
        Filament::setCurrentPanel('client');
        $this->actingAs($this->user, 'client');
    }

    /** @return list<array<string, mixed>> one PlaceOrder item of the product */
    private function items(Product $product, int $count = 1): array
    {
        return app(ClientOrder::class)->cartItems(array_fill(0, $count, ['product' => $product->id]));
    }

    public function test_the_product_form_saves_both_limits_and_unlimited_clears_them(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(CreateProduct::class)
            ->fillForm([
                'name' => 'Trial', 'type' => 'other', 'billing_cycle' => 'monthly',
                'prices.monthly.enabled' => true, 'prices.monthly.price_cents' => '0',
                'stock_mode' => 'limited', 'stock' => '5',
                'client_limit_mode' => 'limited', 'client_limit' => '1',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $product = Product::query()->sole();
        $this->assertSame([5, 1], [$product->stock, $product->client_limit]);

        Livewire::test(EditProduct::class, ['record' => $product->id])
            ->assertFormSet(['stock_mode' => 'limited', 'stock' => 5, 'client_limit_mode' => 'limited'])
            ->fillForm(['stock_mode' => 'unlimited'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame([null, 1], [$product->fresh()->stock, $product->fresh()->client_limit]);

        // A limit needs a number, and a client limit of at least one
        Livewire::test(EditProduct::class, ['record' => $product->id])
            ->fillForm(['stock_mode' => 'limited', 'stock' => null, 'client_limit' => '0'])
            ->call('save')
            ->assertHasFormErrors(['stock' => 'required', 'client_limit']);
    }

    public function test_each_order_takes_one_and_a_cancelled_order_gives_it_back(): void
    {
        $product = $this->product(['stock' => 3]);

        $order = app(ClientOrder::class)->place($this->client, ['lines' => [['product' => $product->id], ['product' => $product->id]]]);
        $this->assertSame(1, $product->fresh()->stock);

        app(CancelOrder::class)->handle($order);
        $this->assertSame(3, $product->fresh()->stock);

        // Unlimited stays unlimited
        $other = $this->product(['name' => 'Other']);
        app(ClientOrder::class)->place($this->client, ['product' => $other->id]);
        $this->assertNull($other->fresh()->stock);
    }

    public function test_clients_cannot_order_more_than_are_left(): void
    {
        $product = $this->product(['stock' => 1]);
        $this->asClient();

        Livewire::test(OrderPage::class)
            ->fillForm(['product' => (string) $product->id])
            ->call('addToCart')
            ->assertHasNoFormErrors();

        // A second one in the same cart is one too many
        Livewire::test(OrderPage::class)
            ->fillForm(['product' => (string) $product->id])
            ->call('addToCart')
            ->assertHasFormErrors(['product']);

        // Someone else took the last one meanwhile: the cart says so and the order is not placed
        $product->update(['stock' => 0]);

        Livewire::test(Cart::class)
            ->assertSee('Dedicated server is out of stock.')
            ->call('placeOrder')
            ->assertNotified(__('The order was not placed'));
        $this->assertSame(0, Order::query()->count());
    }

    public function test_place_order_refuses_a_client_order_once_the_last_one_is_gone_but_lets_an_admin_order(): void
    {
        $product = $this->product(['stock' => 2]);
        $items = $this->items($product, 2);
        $product->update(['stock' => 1]); // taken after the cart was checked

        try {
            app(PlaceOrder::class)->handle($this->client, $items, inStockOnly: true);
            $this->fail('An order of two with one left was placed.');
        } catch (ValidationException $e) {
            $this->assertSame(['Only 1 of Dedicated server left.'], $e->errors()['product']);
        }
        $this->assertSame(0, Order::query()->count());
        $this->assertSame(1, $product->fresh()->stock);

        // An admin's order goes ahead and leaves none
        app(PlaceOrder::class)->handle($this->client, $items);
        $this->assertSame(0, $product->fresh()->stock);
        $this->assertSame(2, Service::query()->where('product_id', $product->id)->count());
    }

    public function test_place_order_checks_the_client_limit_again_under_a_lock(): void
    {
        // Two checkouts at once: both carts passed the check before either order was placed
        $trial = $this->product(['name' => 'Free trial', 'client_limit' => 1]);
        $items = $this->items($trial);
        app(PlaceOrder::class)->handle($this->client, $items, inStockOnly: true);

        try {
            app(PlaceOrder::class)->handle($this->client, $items, inStockOnly: true);
            $this->fail('A second free trial was placed.');
        } catch (ValidationException $e) {
            $this->assertSame(['Free trial is limited to 1 per client, and you already have 1.'], $e->errors()['product']);
        }
        $this->assertSame(1, $trial->servicesOf($this->client));
    }

    public function test_an_out_of_stock_product_shows_but_cannot_be_chosen(): void
    {
        $product = $this->product(['stock' => 0]);
        $this->asClient();

        Livewire::test(OrderPage::class)
            ->assertSee('Dedicated server')
            ->assertSee(__('Out of stock'))
            ->fillForm(['product' => (string) $product->id])
            ->call('addToCart')
            ->assertHasFormErrors(['product']);

        $this->assertSame([], ClientOrder::cart());
        $this->expectException(ValidationException::class);
        app(ClientOrder::class)->addToCart(['product' => $product->id], $this->client);
    }

    public function test_the_client_limit_counts_the_services_the_client_has(): void
    {
        $trial = $this->product(['name' => 'Free trial', 'client_limit' => 1]);
        $this->asClient();

        // Ordered once: a second one is refused, with why
        app(ClientOrder::class)->place($this->client, ['product' => $trial->id]);

        Livewire::test(OrderPage::class)
            ->fillForm(['product' => (string) $trial->id])
            ->call('addToCart')
            ->assertHasFormErrors(['product' => 'Free trial is limited to 1 per client, and you already have 1.']);

        // Cancelled and terminated services don't count
        Service::query()->where('product_id', $trial->id)->update(['status' => ServiceStatus::Terminated]);
        Livewire::test(OrderPage::class)
            ->fillForm(['product' => (string) $trial->id])
            ->call('addToCart')
            ->assertHasNoFormErrors();

        // Nor can two go in one cart
        Livewire::test(OrderPage::class)
            ->fillForm(['product' => (string) $trial->id])
            ->call('addToCart')
            ->assertHasFormErrors(['product' => 'Free trial is limited to 1 per client.']);

        // Another client still can, and an admin's order is not limited
        $this->assertNull($trial->limitProblem(Client::factory()->create()));
        $items = $this->items($trial);
        app(PlaceOrder::class)->handle($this->client, $items);
        app(PlaceOrder::class)->handle($this->client, $items);
        $this->assertSame(2, $trial->servicesOf($this->client));
    }

    public function test_a_visitors_cart_is_checked_against_the_limit_once_they_log_in(): void
    {
        $trial = $this->product(['name' => 'Free trial', 'client_limit' => 1]);
        Service::factory()->create(['client_id' => $this->client->id, 'product_id' => $trial->id, 'type' => ServiceType::Other, 'status' => ServiceStatus::Active]);

        // A visitor has no services yet
        app(ClientOrder::class)->addToCart(['product' => $trial->id]);

        $this->asClient();
        Livewire::test(Cart::class)
            ->assertSee('Free trial is limited to 1 per client, and you already have 1.')
            ->call('placeOrder')
            ->assertNotified(__('The order was not placed'));
        $this->assertSame(0, Order::query()->count());
    }

    public function test_the_api_holds_to_both_limits(): void
    {
        $trial = $this->product(['name' => 'Free trial', 'client_limit' => 1, 'stock' => 5]);
        [, $token] = ApiKey::generate(['name' => 'Website', 'permissions' => OpenApi::permissions()]);
        $call = fn () => $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->postJson('/api/v1/orders', ['client_id' => $this->client->id, 'items' => [['product_id' => $trial->id]]]);

        $call()->assertCreated();
        $this->assertSame(4, $trial->fresh()->stock);
        $call()->assertUnprocessable()->assertJsonValidationErrors('items');
    }

    public function test_public_pages_show_out_of_stock_instead_of_order(): void
    {
        $group = ProductGroup::create(['slug' => 'servers', 'names' => ['en' => 'Servers'], 'page' => true]);
        $this->product(['stock' => 0, 'product_group_id' => $group->id]);
        $this->product(['name' => 'Small server', 'product_group_id' => $group->id]);

        $this->get('/servers')->assertOk()
            ->assertSeeInOrder(['Dedicated server', __('Out of stock'), 'Small server', __('Order')]);
    }
}
