<?php

namespace Tests\Feature;

use App\Actions\ClientOrder;
use App\Enums\ServiceType;
use App\Enums\UserRole;
use App\Filament\Client\Pages\Cart;
use App\Filament\Client\Pages\Order as OrderPage;
use App\Filament\Client\Pages\PublicOrder;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Product;
use App\Models\Registrar;
use App\Models\Service;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/** The public order page: visitors fill a cart before they log in or sign up, and place the order once they are in. */
class PublicOrderTest extends TestCase
{
    use RefreshDatabase;

    private Product $hosting;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        User::factory()->create(['email' => 'admin@example.com']);
        $this->hosting = Product::factory()->create([
            'name' => 'Hosting Business',
            'prices' => ['annually' => ['enabled' => true, 'price_cents' => 9000, 'setup_cents' => 0]],
        ]);
        Registrar::factory()->withTlds()->create();
        config(['gaiacp.client_area.public_orders' => true, 'gaiacp.client_area.signup' => true]);
    }

    public function test_off_by_default_and_only_while_clients_can_order(): void
    {
        config(['gaiacp.client_area.public_orders' => false]);
        $this->get('/cart')->assertNotFound();
        $this->get('/login')->assertOk()->assertDontSee(__('See what you can order'));

        config(['gaiacp.client_area.public_orders' => true, 'gaiacp.client_area.orders' => false]);
        $this->get('/cart')->assertNotFound();

        config(['gaiacp.client_area.orders' => true]);
        $this->get('/cart')->assertOk()->assertSee('Hosting Business')->assertSee('A domain name only')->assertSee('/login');
        $this->get('/login')->assertOk()->assertSee(__('See what you can order'));
    }

    public function test_add_to_cart_ends_the_form_and_the_cart_ends_with_continue(): void
    {
        $this->get('/cart')->assertOk()->assertSeeInOrder([
            'What would you like to order?', 'Add to cart', 'Add what you need to the cart.', 'Continue to sign up',
        ]);
    }

    public function test_a_description_keeps_its_lines_and_decodes_imported_entities(): void
    {
        $this->hosting->update(['description' => "Disk: 2.5GB<br />Parked &amp; Sub-domains: 2\nEmail: 10<script>x</script>"]);

        $this->get('/cart')->assertOk()
            ->assertSee('Disk: 2.5GB<br>Parked &amp; Sub-domains: 2<br>Email: 10x<br><strong>From €7.50 / month</strong><br>Billed €90.00 every year', false)
            ->assertDontSee('&amp;amp;', false);
    }

    public function test_prices_per_month_or_per_cycle(): void
    {
        $this->hosting->update(['prices' => [
            'annually' => ['enabled' => true, 'price_cents' => 24000, 'setup_cents' => 0],
            'biennially' => ['enabled' => true, 'price_cents' => 40000, 'setup_cents' => 1000],
        ]]);

        $this->get('/cart')->assertOk()->assertSee('<strong>From €16.67 / month</strong><br>Billed €400.00 every 2 years + €10.00 setup', false);

        config(['gaiacp.client_area.monthly_prices' => false]);
        $this->get('/cart')->assertOk()->assertSee('<strong>From €400.00 / Biennially + €10.00 setup</strong>', false)->assertDontSee('/ month');
    }

    public function test_a_link_pre_selects_a_product(): void
    {
        $this->get('/cart?product='.$this->hosting->id)->assertOk();

        Filament::setCurrentPanel('client');
        Livewire::withQueryParams(['product' => (string) $this->hosting->id])
            ->test(PublicOrder::class)
            ->assertSet('data.product', (string) $this->hosting->id);
    }

    public function test_a_visitor_fills_the_cart_signs_up_and_places_the_order(): void
    {
        Filament::setCurrentPanel('client');

        Livewire::test(PublicOrder::class)
            ->fillForm(['product' => (string) $this->hosting->id, 'billing_cycle' => 'annually', 'domain_option' => 'register', 'domain' => 'Maria.gr', 'years' => 1])
            ->assertSee('First payment:')
            ->call('addToCart')
            ->assertHasNoFormErrors()
            // The form is empty again for the next item, and the cart lists the first
            ->assertSet('data.product', null)
            ->assertSee('Your cart')
            ->assertSee('Hosting Business')
            ->fillForm(['product' => ClientOrder::DOMAIN_ONLY, 'domain_option' => 'register', 'domain' => 'maria-shop.gr', 'years' => 1])
            ->call('addToCart')
            ->assertHasNoFormErrors()
            ->call('continue')
            ->assertRedirect(Filament::getPanel('client')->getRegistrationUrl());

        // Nothing is ordered yet: the cart waits in the session, and the sign up page says so.
        $this->assertSame(0, Order::query()->count());
        $this->assertSame(2, ClientOrder::cartCount());
        $this->assertSame(Cart::getUrl(panel: 'client'), session('url.intended'));
        $this->get('/signup')->assertOk()->assertSee(__('Your order: :items', ['items' => 'Hosting Business, maria.gr, maria-shop.gr']));

        $client = Client::factory()->create();
        $user = User::factory()->create(['role' => UserRole::Client, 'client_id' => $client->id, 'email' => $client->email]);
        $this->actingAs($user, 'client');

        // Once in, the cart has both; the client places them as one order with one payment notice.
        Livewire::test(Cart::class)
            ->assertSee('Hosting Business')
            ->assertSee('maria-shop.gr')
            ->fillForm(['notes' => 'Call me'])
            ->call('placeOrder')
            ->assertHasNoFormErrors();

        $this->assertSame(0, ClientOrder::cartCount());
        $order = Order::query()->sole();
        $this->assertSame($client->id, $order->client_id);
        $this->assertSame('Call me', $order->notes);
        $this->assertSame(['maria.gr', 'maria.gr', 'maria-shop.gr'], $order->services()->orderBy('id')->pluck('domain')->all());
        $this->assertSame([ServiceType::Hosting, ServiceType::Domain, ServiceType::Domain], $order->services()->orderBy('id')->get()->pluck('type')->all());
        $this->assertSame(1, Invoice::query()->where('order_id', $order->id)->count());
    }

    public function test_continue_alone_adds_what_is_filled_in(): void
    {
        config(['gaiacp.client_area.signup' => false]);
        Filament::setCurrentPanel('client');

        Livewire::test(PublicOrder::class)
            ->assertSee(__('Continue to log in'))
            ->fillForm(['product' => ClientOrder::DOMAIN_ONLY, 'domain_option' => 'register', 'domain' => 'maria.gr', 'years' => 1])
            ->call('continue')
            ->assertRedirect(Filament::getPanel('client')->getLoginUrl());

        $this->get('/login')->assertOk()->assertSee(__('Your order: :items', ['items' => 'maria.gr']))->assertSee(__('Log in to place it.'));
    }

    public function test_the_choice_is_checked_like_an_order(): void
    {
        Service::factory()->create(['type' => ServiceType::Domain, 'domain' => 'taken.gr']);
        Filament::setCurrentPanel('client');

        Livewire::test(PublicOrder::class)
            ->fillForm(['product' => ClientOrder::DOMAIN_ONLY, 'domain_option' => 'register', 'domain' => 'taken.gr', 'years' => 1])
            ->call('addToCart')
            ->assertHasFormErrors(['domain'])
            // The same domain can't be in the cart twice
            ->fillForm(['product' => ClientOrder::DOMAIN_ONLY, 'domain_option' => 'register', 'domain' => 'free.gr', 'years' => 1])
            ->call('addToCart')
            ->assertHasNoFormErrors()
            ->fillForm(['product' => (string) $this->hosting->id, 'billing_cycle' => 'annually', 'domain_option' => 'register', 'domain' => 'free.gr', 'years' => 1])
            ->call('addToCart')
            ->assertHasFormErrors(['domain']);

        $this->assertSame(1, ClientOrder::cartCount());
    }

    public function test_items_can_be_removed_and_epp_codes_are_kept_encrypted(): void
    {
        $key = app(ClientOrder::class)->addToCart(['product' => ClientOrder::DOMAIN_ONLY, 'domain_option' => 'register', 'domain' => 'maria.gr', 'years' => 1, 'epp_code' => 'S3cret!']);

        $this->assertNotSame('S3cret!', session(ClientOrder::CART)[$key]['epp_code']);
        $this->assertSame('S3cret!', ClientOrder::cart()[$key]['epp_code']);

        Filament::setCurrentPanel('client');
        Livewire::test(PublicOrder::class)->assertSee('maria.gr')->call('removeLine', $key)->assertDontSee('Your cart');
        $this->assertSame([], ClientOrder::cart());
    }

    public function test_visitors_see_that_tax_is_added(): void
    {
        config(['gaiacp.tax.enabled' => true, 'gaiacp.tax.inclusive' => false, 'gaiacp.tax.rules' => [['name' => 'ΦΠΑ', 'rate' => 24, 'country' => null]]]);
        Filament::setCurrentPanel('client');

        Livewire::test(PublicOrder::class)
            ->fillForm(['product' => (string) $this->hosting->id, 'billing_cycle' => 'annually', 'domain_option' => 'own', 'domain' => 'maria.gr'])
            ->assertSee(__('First payment: :amount plus tax where it applies', ['amount' => '€90.00']));
    }

    public function test_signed_in_clients_go_to_the_order_page(): void
    {
        $client = Client::factory()->create();
        $user = User::factory()->create(['role' => UserRole::Client, 'client_id' => $client->id, 'email' => $client->email]);

        $this->actingAs($user, 'client')->get('/cart?product='.$this->hosting->id)
            ->assertRedirect(OrderPage::getUrl(['product' => $this->hosting->id], panel: 'client'));
    }

    public function test_cart_shows_products_as_cards_beside_a_summary(): void
    {
        $this->get('/cart')->assertOk()
            ->assertSee('gcp-product-cards', false)
            ->assertSee('gcp-order-summary', false)
            ->assertSee(__('Continue to sign up'));
    }
}
