<?php

namespace Tests\Feature;

use App\Actions\CancelOrder;
use App\Actions\ClientOrder;
use App\Actions\PlaceOrder;
use App\Api\OpenApi;
use App\Enums\CouponType;
use App\Enums\InvoiceStatus;
use App\Enums\ServiceType;
use App\Enums\UserRole;
use App\Filament\Client\Pages\Cart;
use App\Filament\Resources\Coupons\Pages\ManageCoupons;
use App\Filament\Resources\Orders\Pages\CreateOrder;
use App\Invoices\GenerateInvoices;
use App\Models\ApiKey;
use App\Models\Client;
use App\Models\Coupon;
use App\Models\Currency;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Product;
use App\Models\Registrar;
use App\Models\User;
use App\Support\Coupons;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/** Coupons (Billing → Coupons): a code for a discount on the first payment, or on renewals too, with its limits. */
class CouponsTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private User $user;

    private Product $hosting;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        User::factory()->create(['email' => 'admin@example.com']);
        $this->client = Client::factory()->create();
        $this->user = User::factory()->create(['role' => UserRole::Client, 'client_id' => $this->client->id, 'email' => $this->client->email]);
        $this->hosting = Product::factory()->create([
            'name' => 'Hosting Business',
            'prices' => [
                'monthly' => ['enabled' => true, 'price_cents' => 800, 'setup_cents' => 0],
                'annually' => ['enabled' => true, 'price_cents' => 9000, 'setup_cents' => 1000],
            ],
        ]);
        Registrar::factory()->withTlds()->create();
    }

    private function coupon(array $attributes = []): Coupon
    {
        return Coupon::create($attributes + ['code' => 'welcome', 'type' => CouponType::Percent, 'value' => 10]);
    }

    /** @return list<array<string, mixed>> */
    private function items(array $line = []): array
    {
        return app(ClientOrder::class)->items($line + ['product' => (string) $this->hosting->id, 'billing_cycle' => 'annually', 'domain_option' => 'own', 'domain' => 'maria.gr'], $this->client);
    }

    private function place(Coupon $coupon, ?Client $client = null, array $line = []): Order
    {
        $client ??= $this->client;

        return app(PlaceOrder::class)->handle($client, app(ClientOrder::class)->items($line + ['product' => (string) $this->hosting->id, 'billing_cycle' => 'annually', 'domain_option' => 'own', 'domain' => 'maria.gr'], $client), coupon: $coupon);
    }

    public function test_a_client_enters_a_code_in_the_cart_and_only_the_first_payment_is_lower(): void
    {
        $this->coupon();
        Filament::setCurrentPanel('client');
        $this->actingAs($this->user, 'client');
        app(ClientOrder::class)->addToCart(['product' => (string) $this->hosting->id, 'billing_cycle' => 'annually', 'domain_option' => 'own', 'domain' => 'maria.gr'], $this->client);

        Livewire::test(Cart::class)
            ->fillForm(['coupon' => ' Welcome '])
            // 10% of the price, not of the setup fee
            ->assertSee('You save €9.00.')
            ->assertSee('First payment: €91.00')
            ->call('placeOrder')
            ->assertHasNoFormErrors();

        $order = Order::query()->sole();
        $service = $order->services()->sole();
        $this->assertSame('WELCOME', $order->coupon->code);
        $this->assertSame(900, $order->discount_cents);
        $this->assertSame(9000, $service->price_cents, 'renewals at the full price');
        $this->assertSame(9100, $service->first_payment_cents);

        $line = Invoice::query()->where('order_id', $order->id)->sole()->items()->sole();
        $this->assertSame(9100, $line->amount_cents);
        $this->assertStringContainsString('Coupon WELCOME', $line->description);
        $this->assertNull(session(Coupons::SESSION), 'forgotten once used');
    }

    public function test_a_wrong_code_stops_the_order_with_why(): void
    {
        Filament::setCurrentPanel('client');
        $this->actingAs($this->user, 'client');
        app(ClientOrder::class)->addToCart(['product' => (string) $this->hosting->id, 'billing_cycle' => 'monthly', 'domain_option' => 'own', 'domain' => 'maria.gr'], $this->client);
        $this->coupon(['code' => 'OTHER']);

        Livewire::test(Cart::class)
            ->fillForm(['coupon' => 'NOPE'])
            ->assertSee('This coupon code is not valid.')
            ->call('placeOrder')
            ->assertHasFormErrors(['coupon']);

        $this->assertSame(0, Order::query()->count());
    }

    public function test_a_coupon_link_fills_the_cart(): void
    {
        $this->coupon();
        Filament::setCurrentPanel('client');
        $this->actingAs($this->user, 'client');

        $this->get(Cart::getUrl(['coupon' => 'welcome'], panel: 'client'))->assertOk();
        $this->assertSame('WELCOME', session(Coupons::SESSION));
        Livewire::test(Cart::class)->assertSchemaStateSet(['coupon' => 'WELCOME']);
    }

    public function test_a_recurring_fixed_amount_for_chosen_domain_endings_lowers_renewals_too(): void
    {
        Currency::create(['code' => 'USD', 'prefix' => '$', 'rate' => 2]);
        $coupon = $this->coupon(['type' => CouponType::Fixed, 'value' => 500, 'recurring' => true, 'tlds' => ['.GR']]);
        $items = app(ClientOrder::class)->items(['product' => (string) $this->hosting->id, 'billing_cycle' => 'monthly', 'domain_option' => 'register', 'domain' => 'maria.gr', 'years' => 1], $this->client);

        $order = app(PlaceOrder::class)->handle($this->client, $items, coupon: $coupon);

        $hosting = $order->services()->where('type', ServiceType::Hosting)->sole();
        $domain = $order->services()->where('type', ServiceType::Domain)->sole();
        $this->assertSame([800, null, null], [$hosting->price_cents, $hosting->first_payment_cents, $hosting->coupon_id], 'not covered');
        $this->assertSame([1000, null, $coupon->id], [$domain->price_cents, $domain->first_payment_cents, $domain->coupon_id]);
        $this->assertSame(500, $order->discount_cents);
        $this->assertStringContainsString('Coupon WELCOME', GenerateInvoices::describe($domain->refresh()->load('payments')));

        // A fixed amount is in the base currency, converted for the client's
        $this->assertSame(1000, $coupon->discountOn(5000, 'USD'));
        $this->assertSame(300, $coupon->discountOn(300, 'EUR'), 'never more than the price');
    }

    public function test_its_limits(): void
    {
        $coupon = $this->coupon(['max_uses' => 1]);
        $order = $this->place($coupon);

        $this->assertSame('This coupon has been used up.', Coupons::problem($coupon, Client::factory()->create()));
        $this->expectValidation(fn () => $this->place($coupon, Client::factory()->create()), 'This coupon has been used up.');

        // A cancelled order gives its use back
        app(CancelOrder::class)->handle($order);
        $this->assertNull(Coupons::problem($coupon, Client::factory()->create()));

        $coupon->update(['max_uses' => null, 'once_per_client' => true]);
        $this->place($coupon);
        $this->assertSame('You have already used this coupon.', Coupons::problem($coupon, $this->client));

        $coupon->update(['once_per_client' => false, 'new_clients_only' => true]);
        $this->assertSame('This coupon is for new clients only.', Coupons::problem($coupon, $this->client));
        $this->assertNull(Coupons::problem($coupon, Client::factory()->create()));

        $coupon->update(['new_clients_only' => false, 'starts_on' => today()->addDay()]);
        $this->assertSame('This coupon is not valid today.', Coupons::problem($coupon, $this->client));
        $coupon->update(['starts_on' => null, 'ends_on' => today()->subDay()]);
        $this->assertSame('This coupon is not valid today.', Coupons::problem($coupon, $this->client));
        $coupon->update(['ends_on' => today(), 'active' => false]);
        $this->assertSame('This coupon code is not valid.', Coupons::problem($coupon, $this->client));

        $coupon->update(['active' => true, 'product_ids' => [Product::factory()->create()->id]]);
        $this->assertSame('This coupon does not cover anything in this order.', Coupons::problem($coupon, $this->client, $this->items()));
        $coupon->update(['product_ids' => [$this->hosting->id]]);
        $this->assertNull(Coupons::problem($coupon, $this->client, $this->items()));
    }

    public function test_nothing_left_to_pay_records_the_notice_as_paid(): void
    {
        $order = $this->place($this->coupon(['value' => 100]), line: ['billing_cycle' => 'monthly']);

        $notice = Invoice::query()->where('order_id', $order->id)->sole();
        $this->assertSame(InvoiceStatus::Paid, $notice->status);
        $this->assertSame(0, $notice->total_cents);
        $this->assertSame(800, $order->services()->sole()->price_cents);
    }

    public function test_the_api_takes_a_code(): void
    {
        $this->coupon();
        [, $token] = ApiKey::generate(['name' => 'Website', 'permissions' => OpenApi::permissions()]);
        $api = $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json']);
        $body = ['client_id' => $this->client->id, 'items' => [['product_id' => $this->hosting->id, 'billing_cycle' => 'monthly', 'domain' => 'maria.gr']]];

        $api->postJson('/api/v1/orders', $body + ['coupon' => 'nope'])->assertUnprocessable()->assertJsonValidationErrors('coupon');
        $api->postJson('/api/v1/orders', $body + ['coupon' => 'welcome'])->assertCreated()
            ->assertJsonPath('data.coupon', 'WELCOME')
            ->assertJsonPath('data.discount_cents', 80)
            ->assertJsonPath('data.total_cents', 720);
    }

    public function test_admins_add_coupons_and_use_them_on_new_order(): void
    {
        $this->actingAs(User::factory()->create());
        $this->hosting->update(['type' => ServiceType::Other]);

        Livewire::test(ManageCoupons::class)
            ->callAction('create', ['code' => 'spring-26', 'type' => 'fixed', 'value' => '5,50', 'product_ids' => [$this->hosting->id], 'max_uses' => 10])
            ->assertHasNoFormErrors();

        $coupon = Coupon::query()->sole();
        $this->assertSame(['SPRING-26', CouponType::Fixed, 550, [$this->hosting->id]], [$coupon->code, $coupon->type, $coupon->value, $coupon->product_ids]);

        $undo = Repeater::fake();
        Livewire::test(CreateOrder::class)
            ->fillForm(['client_id' => $this->client->id, 'items' => [['type' => 'other']]])
            ->fillForm(['items.0.product_id' => $this->hosting->id, 'items.0.billing_cycle' => 'monthly', 'coupon_id' => $coupon->id])
            ->assertSee('First payment total: €2.50')
            ->call('create')
            ->assertHasNoFormErrors();
        $undo();

        $order = Order::query()->sole();
        $this->assertSame([$coupon->id, 550], [$order->coupon_id, $order->discount_cents]);
        $this->assertSame(250, $order->services()->sole()->first_payment_cents);

        Livewire::test(ManageCoupons::class)->assertSee('1 / 10')->assertTableActionHidden('delete', $coupon);
    }

    private function expectValidation(callable $callback, string $message): void
    {
        try {
            $callback();
            $this->fail('No validation error');
        } catch (ValidationException $e) {
            $this->assertSame($message, $e->errors()['coupon'][0]);
        }
    }
}
