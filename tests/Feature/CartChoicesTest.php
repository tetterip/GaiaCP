<?php

namespace Tests\Feature;

use App\Actions\ClientOrder;
use App\Enums\UserRole;
use App\Filament\Client\Pages\Cart;
use App\Livewire\CartDrawer;
use App\Models\Client;
use App\Models\Product;
use App\Models\Registrar;
use App\Models\Tld;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** The billing cycle and domain term changed on a cart item, and the cart in the top bar opening from the side. */
class CartChoicesTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private Product $hosting;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = Client::factory()->create();
        $this->actingAs(User::factory()->create(['role' => UserRole::Client, 'client_id' => $this->client->id, 'email' => $this->client->email]), 'client');
        Filament::setCurrentPanel('client');

        $this->hosting = Product::factory()->create([
            'name' => 'Hosting Business',
            'prices' => [
                'monthly' => ['enabled' => true, 'price_cents' => 800, 'setup_cents' => 0],
                'annually' => ['enabled' => true, 'price_cents' => 9000, 'setup_cents' => 1000],
            ],
        ]);
        Registrar::factory()->withTlds()->create();
        Tld::query()->where('tld', '.gr')->update(['register_2y_cents' => 2800]);
    }

    public function test_a_cart_item_offers_its_cycles_and_terms_with_their_price(): void
    {
        $order = app(ClientOrder::class);
        $hosting = ['product' => (string) $this->hosting->id, 'billing_cycle' => 'monthly', 'domain_option' => 'register', 'domain' => 'shop.gr', 'years' => 1];

        $choices = collect($order->cartChoices($hosting, $this->client))->keyBy('field');
        $this->assertSame(['monthly', 'annually'], array_keys($choices['billing_cycle']['options']));
        $this->assertSame('monthly', $choices['billing_cycle']['value']);
        // The whole item's first payment: monthly 8 + domain 15, annually 90 + setup 10 + domain 15
        $this->assertStringContainsString('23', $choices['billing_cycle']['options']['monthly']);
        $this->assertStringContainsString('115', $choices['billing_cycle']['options']['annually']);
        $this->assertSame([1, 2], array_keys($choices['years']['options']));
        $this->assertSame('Domain term', $choices['years']['label']);

        // A domain on its own: the term only; .com has one term, so nothing to choose
        $this->assertSame(['years'], array_column($order->cartChoices(['product' => 'domain', 'domain' => 'shop.gr', 'years' => 1], $this->client), 'field'));
        $this->assertSame([], $order->cartChoices(['product' => 'domain', 'domain' => 'shop.com', 'years' => 1], $this->client));
    }

    public function test_the_cart_changes_an_items_cycle_and_term_in_place(): void
    {
        $key = app(ClientOrder::class)->addToCart(['product' => (string) $this->hosting->id, 'billing_cycle' => 'monthly', 'domain_option' => 'register', 'domain' => 'shop.gr', 'years' => 1], $this->client);

        Livewire::test(Cart::class)
            ->assertSee('shop.gr: registration for 1 year')
            ->call('changeLine', $key, 'billing_cycle', 'annually')
            ->assertDispatched('cart-updated')
            ->call('changeLine', $key, 'years', '2')
            ->assertSee('shop.gr: registration for 2 years');

        $this->assertSame('annually', ClientOrder::cart()[$key]['billing_cycle']);
        $this->assertSame(2, ClientOrder::cart()[$key]['years']);

        // A term the TLD does not sell, or a field that is not a choice, changes nothing
        Livewire::test(Cart::class)->call('changeLine', $key, 'years', '5')->assertNotified();
        Livewire::test(Cart::class)->call('changeLine', $key, 'domain', 'other.gr');
        $this->assertSame(2, ClientOrder::cart()[$key]['years']);
        $this->assertSame('shop.gr', ClientOrder::cart()[$key]['domain']);
    }

    public function test_the_top_bar_cart_lists_the_items_and_opens_when_asked(): void
    {
        Livewire::test(CartDrawer::class)->assertSee(__('Your cart is empty.'))->assertDontSee(__('Checkout'));

        $key = app(ClientOrder::class)->addToCart(['product' => 'domain', 'domain' => 'shop.gr', 'years' => 1], $this->client);

        Livewire::test(CartDrawer::class)
            ->assertSee(['shop.gr', 'First payment', __('Checkout'), Cart::getUrl()])
            ->call('open')
            ->assertDispatched('open-modal', id: CartDrawer::MODAL)
            ->call('removeLine', $key)
            ->assertDispatched('cart-updated')
            ->assertSee(__('Your cart is empty.'));

        $this->get('/dashboard')->assertOk()->assertSee('gcp-cart-icon', false);
    }
}
