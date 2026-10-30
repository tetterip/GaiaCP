<?php

namespace Tests\Feature;

use App\Actions\ClientOrder;
use App\Actions\PlaceOrder;
use App\Enums\BillingCycle;
use App\Enums\CouponType;
use App\Enums\ServiceType;
use App\Enums\UserRole;
use App\Filament\Client\Pages\Order as OrderPage;
use App\Filament\Resources\Orders\Pages\CreateOrder;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Tlds\Pages\ManageTlds;
use App\Models\Client;
use App\Models\Coupon;
use App\Models\Product;
use App\Models\Registrar;
use App\Models\Tld;
use App\Models\User;
use App\Support\Coupons;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/** Sale prices: a product's per cycle and a TLD's for registering and transferring, between two days, for the first period. */
class SalePricesTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private User $user;

    private Product $hosting;

    private Tld $gr;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->travelTo('2026-11-10 10:00');

        User::factory()->create(['email' => 'admin@example.com']);
        $this->client = Client::factory()->create();
        $this->user = User::factory()->create(['role' => UserRole::Client, 'client_id' => $this->client->id, 'email' => $this->client->email]);
        $this->hosting = Product::factory()->create([
            'name' => 'Hosting Business',
            'type' => ServiceType::Hosting,
            'prices' => [
                'monthly' => ['enabled' => true, 'price_cents' => 800, 'setup_cents' => 0],
                'annually' => ['enabled' => true, 'price_cents' => 9000, 'setup_cents' => 1000, 'sale_cents' => 4500],
            ],
            'sale_starts_on' => '2026-11-01',
            'sale_ends_on' => '2026-11-30',
        ]);
        Registrar::factory()->withTlds()->create();
        $this->gr = Tld::query()->where('tld', '.gr')->sole();
        $this->gr->update(['transfer_1y_cents' => 1200, 'sale_prices' => ['register_1y_cents' => 500], 'sale_ends_on' => '2026-11-30']);
    }

    /** @return list<array<string, mixed>> */
    private function items(array $line = []): array
    {
        return app(ClientOrder::class)->items($line + ['product' => (string) $this->hosting->id, 'billing_cycle' => 'annually', 'domain_option' => 'own', 'domain' => 'maria.gr'], $this->client);
    }

    public function test_a_sale_runs_from_its_first_to_its_last_day(): void
    {
        $this->assertTrue($this->hosting->onSale());
        $this->assertSame(4500, $this->hosting->salePriceFor(BillingCycle::Annually));
        $this->assertNull($this->hosting->salePriceFor(BillingCycle::Monthly), 'a cycle without a sale price');
        $this->assertSame(800, $this->hosting->orderPriceFor(BillingCycle::Monthly));

        $this->travelTo('2026-11-30 23:59');
        $this->assertTrue($this->hosting->onSale(), 'the last day counts');

        $this->travelTo('2026-12-01 00:00');
        $this->assertFalse($this->hosting->onSale());
        $this->assertNull($this->hosting->salePriceFor(BillingCycle::Annually));
        $this->assertSame(9000, $this->hosting->orderPriceFor(BillingCycle::Annually));

        $this->travelTo('2026-10-31 12:00');
        $this->assertFalse($this->hosting->onSale(), 'not started yet');

        // No price lower than the normal one: no sale, whatever the days
        $this->travelTo('2026-11-10');
        $this->hosting->update(['prices' => ['annually' => ['enabled' => true, 'price_cents' => 9000, 'sale_cents' => 9000]]]);
        $this->assertFalse($this->hosting->onSale());
    }

    public function test_an_order_pays_the_sale_price_once_and_renews_at_the_price(): void
    {
        $items = $this->items(['domain_option' => 'register', 'domain' => 'maria.gr']);

        $this->assertSame([9000, 4500 + 1000, 4500], [$items[0]['price_cents'], $items[0]['first_payment_cents'], $items[0]['sale_cents']]);
        // The domain: its registration's sale price first, then the renew price
        $this->assertSame([1500, 500, 500], [$items[1]['price_cents'], $items[1]['first_payment_cents'], $items[1]['sale_cents']]);

        $order = app(PlaceOrder::class)->handle($this->client, $items);
        $hosting = $order->services()->where('type', ServiceType::Hosting)->sole();
        $this->assertSame([9000, 5500], [$hosting->price_cents, $hosting->first_payment_cents]);

        // Transfers have no sale price here
        $this->assertSame([1200, null], [
            $this->items(['domain_option' => 'transfer', 'domain' => 'nikos.gr'])[1]['first_payment_cents'],
            $this->items(['domain_option' => 'transfer', 'domain' => 'nikos.gr'])[1]['sale_cents'],
        ]);

        $this->travelTo('2026-12-01');
        $after = $this->items();
        $this->assertSame([9000, 10000, null], [$after[0]['price_cents'], $after[0]['first_payment_cents'], $after[0]['sale_cents']]);
    }

    public function test_a_coupon_skips_items_on_sale_unless_it_says_so(): void
    {
        $coupon = Coupon::create(['code' => 'welcome', 'type' => CouponType::Percent, 'value' => 10]);

        try {
            Coupons::check($coupon, $this->client, $this->items());
            $this->fail('A coupon must not lower a sale price by default.');
        } catch (ValidationException $e) {
            $this->assertSame(__('This coupon can\'t be used on items on sale.'), $e->errors()['coupon'][0]);
        }

        $coupon->update(['on_sale' => true]);
        $applied = Coupons::apply($coupon, $this->items(), 'EUR');

        // 10% of the sale price, never of the setup fee
        $this->assertSame(450, $applied['discount_cents']);
        $this->assertSame(4050 + 1000, $applied['items'][0]['first_payment_cents']);
        $this->assertSame(9000, $applied['items'][0]['price_cents']);
    }

    public function test_clients_see_the_sale_badge_and_the_normal_price_struck_through(): void
    {
        Filament::setCurrentPanel('client');
        $this->actingAs($this->user, 'client');

        Livewire::test(OrderPage::class)
            ->assertSee('gcp-sale', false)
            ->assertSee('<strong>From €3.75 / month</strong><br><s class="gcp-was">€7.50 / month</s>', false)
            ->assertSee(__('First period only, until :date', ['date' => '30/11/2026']));

        config(['gaiacp.public_area.domain_prices' => true]);
        $this->get('/domain-prices')->assertOk()->assertSee('<s class="gcp-was">€15.00</s> €5.00', false);

        auth('client')->logout();
        config(['gaiacp.public_area.home' => true, 'gaiacp.client_area.public_orders' => true]);
        $this->get('/')->assertOk()->assertSeeInOrder(['Hosting Business', 'gcp-sale', 'From €3.75 / month', '€7.50 / month'], false);

        $this->travelTo('2026-12-01');
        $this->get('/')->assertOk()->assertDontSee('class="gcp-popular gcp-sale"', false)->assertDontSee('class="gcp-was"', false);
    }

    public function test_admins_set_a_sale_and_new_order_fills_in_the_sale_price(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(EditProduct::class, ['record' => $this->hosting->getRouteKey()])
            ->fillForm(['prices.monthly.sale_cents' => '9'])
            ->call('save')
            ->assertHasFormErrors(['prices.monthly.sale_cents']);

        Livewire::test(EditProduct::class, ['record' => $this->hosting->getRouteKey()])
            ->fillForm(['prices.monthly.sale_cents' => '5,50', 'sale_starts_on' => '2026-11-05', 'sale_ends_on' => '2026-11-20'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->hosting->refresh();
        $this->assertSame([550, '2026-11-20'], [$this->hosting->salePriceFor(BillingCycle::Monthly), $this->hosting->sale_ends_on->toDateString()]);
        $this->assertSame(9000, $this->hosting->priceFor(BillingCycle::Annually), 'the prices stay as they were');

        Livewire::test(ManageTlds::class)
            ->callAction(TestAction::make('edit')->table($this->gr), ['sale_prices.transfer_1y_cents' => '8'])
            ->assertHasNoFormErrors();
        $this->assertSame(800, $this->gr->refresh()->salePriceCents('transfer', 1));

        $undo = Repeater::fake();
        Livewire::test(CreateOrder::class)
            ->fillForm(['client_id' => $this->client->id, 'items' => [['type' => 'other']]])
            ->fillForm(['items.0.product_id' => $this->hosting->id, 'items.0.billing_cycle' => 'monthly'])
            ->assertSchemaStateSet(['items.0.price_cents' => '8.00', 'items.0.first_payment_cents' => '5.50']);
        $undo();
    }
}
