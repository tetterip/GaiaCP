<?php

namespace Tests\Feature;

use App\Actions\ClientOrder;
use App\Actions\CompleteOrder;
use App\Enums\ServiceStatus;
use App\Enums\ServiceType;
use App\Enums\UserRole;
use App\Filament\Client\Pages\Cart;
use App\Filament\Client\Pages\Order as OrderPage;
use App\Filament\Client\Pages\PublicOrder;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/** Add-ons ticked with a product on the Order page and the public cart: in the same order and notice, on its cycle. */
class ClientOrderAddonsTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private User $user;

    private Product $hosting;

    private Product $backup;

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
                'annually' => ['enabled' => true, 'price_cents' => 9000, 'setup_cents' => 0],
            ],
        ]);
        $this->backup = Product::factory()->create([
            'name' => 'Daily backup', 'type' => ServiceType::Other, 'package' => null, 'addon' => true,
            'prices' => ['annually' => ['enabled' => true, 'price_cents' => 2000, 'setup_cents' => 500]],
        ]);
        $this->backup->addonFor()->attach($this->hosting);
    }

    private function page(): Testable
    {
        Filament::setCurrentPanel('client');
        $this->actingAs($this->user, 'client');

        return Livewire::test(OrderPage::class);
    }

    public function test_an_add_on_ticked_with_the_product_joins_its_order_and_notice(): void
    {
        $this->page()
            ->fillForm(['product' => (string) $this->hosting->id, 'billing_cycle' => 'annually', 'domain_option' => 'own', 'domain' => 'maria.gr'])
            ->assertSee('Daily backup')
            ->assertSee('€20.00 / Annually + €5.00 setup', false)
            ->fillForm(['addons' => [(string) $this->backup->id]])
            ->assertSee('First payment: €115.00')
            ->checkout()
            ->assertHasNoFormErrors();

        $order = Order::query()->sole();
        [$hosting, $addon] = $order->services()->orderBy('id')->get()->all();
        $this->assertSame($hosting->id, $addon->parent_service_id);
        $this->assertSame('maria.gr', $addon->domain);
        $this->assertSame('Daily backup', $addon->label);
        $this->assertSame(ServiceStatus::Pending, $addon->status);
        $this->assertSame([2000, 2500], [$addon->price_cents, $addon->first_payment_cents]);
        $this->assertSame($hosting->billing_cycle, $addon->billing_cycle);
        $this->assertSame(11500, Invoice::query()->where('order_id', $order->id)->sole()->subtotal_cents);

        app(CompleteOrder::class)->handle($order);
        $this->assertSame(ServiceStatus::Active, $addon->fresh()->status);
    }

    public function test_only_add_ons_priced_for_the_cycle_are_offered(): void
    {
        Product::factory()->create(['name' => 'Other product backup', 'type' => ServiceType::Other, 'package' => null, 'addon' => true,
            'prices' => ['annually' => ['enabled' => true, 'price_cents' => 100, 'setup_cents' => 0]]])
            ->addonFor()->attach(Product::factory()->create());

        $page = $this->page()
            ->fillForm(['product' => (string) $this->hosting->id, 'billing_cycle' => 'annually', 'domain_option' => 'own', 'domain' => 'maria.gr', 'addons' => [(string) $this->backup->id]])
            ->assertDontSee('Other product backup');

        // Monthly has no backup price: it is no longer offered nor ticked.
        $page->fillForm(['billing_cycle' => 'monthly'])
            ->assertSet('data.addons', [])
            ->assertDontSee('Daily backup');

        $this->expectException(ValidationException::class);
        app(ClientOrder::class)->items(['product' => $this->hosting->id, 'billing_cycle' => 'monthly', 'domain' => 'maria.gr', 'addons' => [$this->backup->id]], $this->client);
    }

    public function test_the_public_cart_keeps_the_add_ons(): void
    {
        config(['gaiacp.client_area.public_orders' => true, 'gaiacp.client_area.signup' => true]);
        Filament::setCurrentPanel('client');

        Livewire::test(PublicOrder::class)
            ->fillForm(['product' => (string) $this->hosting->id, 'billing_cycle' => 'annually', 'domain_option' => 'own', 'domain' => 'maria.gr', 'addons' => [(string) $this->backup->id]])
            ->call('continue')
            ->assertHasNoFormErrors();

        $this->actingAs($this->user, 'client');
        Livewire::test(Cart::class)
            ->assertSee('Add-on: Daily backup')
            ->call('placeOrder')
            ->assertHasNoFormErrors();

        $this->assertSame(2, Order::query()->sole()->services()->count());
    }
}
