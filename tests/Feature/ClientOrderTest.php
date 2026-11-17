<?php

namespace Tests\Feature;

use App\Actions\ClientOrder;
use App\Enums\AdminArea;
use App\Enums\BillingCycle;
use App\Enums\DomainRegistrationType;
use App\Enums\ServiceStatus;
use App\Enums\ServiceType;
use App\Enums\UserRole;
use App\Filament\Client\Pages\Cart;
use App\Filament\Client\Pages\Order as OrderPage;
use App\Filament\Client\Resources\Domains\Pages\ListDomains;
use App\Filament\Client\Resources\Services\Pages\ListServices;
use App\Mail\NewOrderMail;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Product;
use App\Models\Registrar;
use App\Models\Service;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/** Client area step 6: a client orders a product, with or without a domain, or a domain on its own. */
class ClientOrderTest extends TestCase
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
        $this->client = Client::factory()->create(['first_name' => 'Maria', 'last_name' => 'Papa']);
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

    private function page(): Testable
    {
        Filament::setCurrentPanel('client');
        $this->actingAs($this->user, 'client');

        return Livewire::test(OrderPage::class);
    }

    public function test_services_and_domains_lists_link_to_the_order_page(): void
    {
        Filament::setCurrentPanel('client');
        $this->actingAs($this->user, 'client');

        // Nothing yet: the empty list's own button, none over it
        Livewire::test(ListServices::class)
            ->assertActionHidden('orderService')
            ->assertSee(__('Order a new service'));

        Service::factory()->create(['client_id' => $this->client->id]);
        Livewire::test(ListServices::class)->assertActionVisible('orderService')->assertActionHasUrl('orderService', OrderPage::getUrl());
        Livewire::test(ListDomains::class)->assertSee(__('Order a domain'));
        $this->get(OrderPage::getUrl(['product' => 'domain']))->assertOk();

        config(['gaiacp.client_area.orders' => false]);
        Livewire::test(ListServices::class)->assertActionHidden('orderService')->assertDontSee(__('Order a new service'));
    }

    public function test_the_catalogue_shows_only_products_clients_may_order(): void
    {
        Product::factory()->create(['name' => 'Secret plan', 'hidden' => true]);
        Product::factory()->create(['name' => 'Old plan', 'retired' => true]);
        Product::factory()->create(['name' => 'Private plan', 'client_visible' => false]);
        Product::factory()->maintenance()->create(['name' => 'Website care', 'client_visible' => true]);
        Product::factory()->create(['name' => 'Unpriced plan', 'prices' => []]);

        $this->page()
            ->assertSee('Hosting Business')
            ->assertSee('A domain name only')
            ->assertDontSee('Secret plan')
            ->assertDontSee('Old plan')
            ->assertDontSee('Private plan')
            ->assertDontSee('Website care')
            ->assertDontSee('Unpriced plan');
    }

    public function test_the_first_payment_shows_the_tax_the_payment_notice_will_add(): void
    {
        config(['gaiacp.tax.enabled' => true, 'gaiacp.tax.inclusive' => false, 'gaiacp.tax.rules' => [['name' => 'ΦΠΑ', 'rate' => 24, 'country' => null]]]);

        $this->page()
            ->fillForm(['product' => (string) $this->hosting->id, 'billing_cycle' => 'annually', 'domain_option' => 'own', 'domain' => 'maria.gr'])
            ->assertSee('First payment: €100.00 + ΦΠΑ 24% €24.00 = €124.00 with tax')
            ->checkout()
            ->assertHasNoFormErrors();

        $this->assertSame(12400, Invoice::query()->sole()->total_cents);
    }

    public function test_a_client_orders_hosting_for_a_domain_they_have(): void
    {
        $this->page()
            ->fillForm(['product' => (string) $this->hosting->id, 'billing_cycle' => 'annually', 'domain_option' => 'own', 'domain' => 'Maria.gr'])
            ->assertSee('First payment: €100.00')
            ->checkout(['notes' => 'Please call me'])
            ->assertHasNoFormErrors()
            ->assertRedirect();

        $order = Order::query()->sole();
        $this->assertSame($this->client->id, $order->client_id);
        $this->assertSame('Please call me', $order->notes);

        $service = $order->services()->sole();
        $this->assertSame(ServiceType::Hosting, $service->type);
        $this->assertSame(ServiceStatus::Pending, $service->status);
        $this->assertSame('maria.gr', $service->domain);
        $this->assertSame(BillingCycle::Annually, $service->billing_cycle);
        $this->assertSame(9000, $service->price_cents);
        $this->assertSame(10000, $service->first_payment_cents);

        $notice = Invoice::query()->where('order_id', $order->id)->sole();
        $this->assertSame(10000, $notice->subtotal_cents);
        $this->assertTrue(ActivityLog::query()->where('event', 'order.client_placed')->where('user_id', $this->user->id)->exists());
        Mail::assertSent(NewOrderMail::class, fn (NewOrderMail $mail) => $mail->hasTo('admin@example.com') && str_contains($mail->render(), 'Hosting Business'));
    }

    public function test_hosting_with_a_new_domain_adds_the_registration_at_the_registrars_price(): void
    {
        $this->page()
            ->fillForm(['product' => (string) $this->hosting->id, 'billing_cycle' => 'monthly', 'domain_option' => 'register', 'domain' => 'new-shop.gr', 'years' => 1])
            ->checkout()
            ->assertHasNoFormErrors();

        $services = Order::query()->sole()->services()->get()->keyBy(fn (Service $s) => $s->type->value);
        $this->assertSame(800, $services['hosting']->price_cents);
        $this->assertSame('new-shop.gr', $services['hosting']->domain);
        $this->assertSame('new-shop.gr', $services['domain']->domain);
        $this->assertSame(1500, $services['domain']->first_payment_cents);
        $this->assertSame(DomainRegistrationType::Register, $services['domain']->domainDetails->registration_type);
        // Registered only when the order is completed.
        $this->assertSame(ServiceStatus::Pending, $services['domain']->status);
    }

    public function test_a_domain_on_its_own_and_its_checks(): void
    {
        $this->page()
            ->fillForm(['product' => 'domain', 'domain_option' => 'register', 'domain' => 'shop.example', 'years' => 1])
            ->checkout()
            ->assertHasErrors(['data.domain']);

        $this->page()
            ->fillForm(['product' => 'domain', 'domain_option' => 'register', 'domain' => 'shop.gr', 'years' => 2])
            ->checkout()
            ->assertHasErrors(['data.years']);

        Service::factory()->domain()->create(['domain' => 'taken.gr']);
        $this->page()
            ->fillForm(['product' => 'domain', 'domain_option' => 'register', 'domain' => 'taken.gr', 'years' => 1])
            ->checkout()
            ->assertHasErrors(['data.domain']);

        $this->page()
            ->fillForm(['product' => 'domain', 'domain_option' => 'transfer', 'domain' => 'shop.gr', 'years' => 1])
            ->checkout()
            ->assertHasFormErrors(['epp_code' => 'required']);

        $this->assertSame(0, Order::query()->count());

        $this->page()
            ->fillForm(['product' => 'domain', 'domain_option' => 'register', 'domain' => 'shop.gr', 'years' => 1])
            ->checkout()
            ->assertHasNoFormErrors();

        $this->assertSame(['shop.gr'], Order::query()->sole()->services()->pluck('domain')->all());
    }

    public function test_the_term_follows_what_the_domain_is_offered_for(): void
    {
        // .gr transfers only for 2 years, at no charge (easy.gr).
        Registrar::query()->where('module', 'easygr')->sole()->tlds()->where('tld', '.gr')->update(['transfer_2y_cents' => 0, 'renew_2y_cents' => 3000]);

        $this->page()
            ->fillForm(['product' => 'domain', 'domain_option' => 'transfer', 'domain' => 'acme.gr', 'epp_code' => 'abc'])
            ->assertSchemaStateSet(['years' => 2])
            ->checkout()
            ->assertHasNoFormErrors();

        $domain = Order::query()->sole()->services()->sole();
        $this->assertSame(BillingCycle::Biennially, $domain->billing_cycle);
        $this->assertSame(0, $domain->first_payment_cents);
        $this->assertSame(DomainRegistrationType::Transfer, $domain->domainDetails->registration_type);
    }

    public function test_a_domain_only_choice_can_be_undone(): void
    {
        $this->page()
            ->fillForm(['product' => 'domain', 'domain_option' => 'transfer', 'domain' => 'shop.gr'])
            ->assertSee(__('I don\'t want a domain: choose something else'))
            ->callAction(TestAction::make('noDomain')->schemaComponent('noDomainActions', schema: 'form'))
            ->assertSchemaStateSet(['product' => null, 'domain' => null, 'domain_option' => 'own'])
            ->assertDontSee(__('Transfer a domain I have elsewhere'));
    }

    public function test_prices_come_from_the_product_not_the_form(): void
    {
        $other = Product::factory()->create(['name' => 'Hidden plan', 'hidden' => true]);

        $this->page()
            ->fillForm(['product' => (string) $other->id, 'billing_cycle' => 'annually', 'domain' => 'maria.gr'])
            ->checkout()
            ->assertHasErrors();

        $this->page()
            ->fillForm(['product' => (string) $this->hosting->id, 'billing_cycle' => 'annually', 'domain_option' => 'own', 'domain' => 'maria.gr', 'price_cents' => 1])
            ->checkout();

        $this->assertSame(9000, Order::query()->sole()->services()->sole()->price_cents);
    }

    public function test_the_admin_email_follows_its_switches(): void
    {
        $limited = User::factory()->limitedTo([AdminArea::Billing])->create(['email' => 'billing@example.com']);
        User::factory()->create(['email' => 'quiet@example.com', 'system_emails' => false]);

        $this->page()
            ->fillForm(['product' => (string) $this->hosting->id, 'billing_cycle' => 'monthly', 'domain_option' => 'own', 'domain' => 'maria.gr'])
            ->checkout();

        Mail::assertSent(NewOrderMail::class, 1);
        Mail::assertNotSent(NewOrderMail::class, fn ($mail) => $mail->hasTo($limited->email) || $mail->hasTo('quiet@example.com'));

        config(['gaiacp.automation.new_order_email' => false]);
        $this->page()
            ->fillForm(['product' => (string) $this->hosting->id, 'billing_cycle' => 'monthly', 'domain_option' => 'own', 'domain' => 'other.gr'])
            ->checkout();

        Mail::assertSent(NewOrderMail::class, 1);
        $this->assertSame(2, Order::query()->count());
    }

    public function test_ordering_can_be_switched_off(): void
    {
        config(['gaiacp.client_area.orders' => false]);

        $this->actingAs($this->user, 'client')->get('/dashboard')->assertOk()->assertDontSee(OrderPage::getUrl(panel: 'client'));
        $this->actingAs($this->user, 'client')->get('/order')->assertForbidden();
    }

    public function test_the_order_page_is_in_the_menu(): void
    {
        $this->actingAs($this->user, 'client')->get('/dashboard')->assertOk()->assertSee(OrderPage::getUrl(panel: 'client'));
        $this->actingAs($this->user, 'client')->get('/order')->assertOk()->assertSee('What would you like to order?');
    }

    public function test_several_items_go_in_the_cart_and_become_one_order(): void
    {
        $this->page()
            ->fillForm(['product' => (string) $this->hosting->id, 'billing_cycle' => 'monthly', 'domain_option' => 'register', 'domain' => 'one.gr', 'years' => 1])
            ->call('addToCart')
            ->assertHasNoFormErrors()
            ->assertRedirect(Cart::getUrl(panel: 'client'));

        Livewire::test(OrderPage::class)
            ->assertSee('Your cart has 1 item.')
            ->fillForm(['product' => (string) $this->hosting->id, 'billing_cycle' => 'annually', 'domain_option' => 'own', 'domain' => 'two.gr'])
            ->call('addToCart')
            ->assertHasNoFormErrors();
        $key = app(ClientOrder::class)->addToCart(['product' => 'domain', 'domain_option' => 'register', 'domain' => 'three.gr', 'years' => 1], $this->client);

        // The top bar shows the cart with how many items are in it
        $this->get(Cart::getUrl(panel: 'client'))->assertOk()->assertSee('gcp-cart-icon', false);

        Livewire::test(Cart::class)
            ->assertSee(['Hosting Business', 'one.gr: registration for 1 year', 'two.gr', 'three.gr'])
            ->call('removeLine', $key)
            ->assertDontSee('three.gr')
            ->call('placeOrder')
            ->assertHasNoFormErrors()
            ->assertRedirect();

        $order = Order::query()->sole();
        $this->assertSame(['one.gr', 'one.gr', 'two.gr'], $order->services()->orderBy('id')->pluck('domain')->all());
        $notice = Invoice::query()->where('order_id', $order->id)->sole();
        $this->assertSame(800 + 1500 + 10000, $notice->subtotal_cents);
        $this->assertSame([], ClientOrder::cart());
        Mail::assertSent(NewOrderMail::class, 1);
    }

    public function test_an_item_that_can_no_longer_be_ordered_stops_the_order(): void
    {
        $this->page()
            ->fillForm(['product' => 'domain', 'domain_option' => 'register', 'domain' => 'late.gr', 'years' => 1])
            ->call('addToCart')
            ->assertHasNoFormErrors();
        Service::factory()->domain()->create(['domain' => 'late.gr']); // someone else ordered it meanwhile

        Livewire::test(Cart::class)
            ->assertSee(__('This domain is already with us. Contact us if it is yours.'))
            ->call('placeOrder')
            ->assertNotified(__('The order was not placed'));

        $this->assertSame(0, Order::query()->count());
        $this->assertSame(1, ClientOrder::cartCount());
    }
}
