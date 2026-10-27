<?php

namespace Tests\Feature;

use App\Actions\ClientOrder;
use App\Enums\BillingCycle;
use App\Enums\ServiceType;
use App\Enums\UserRole;
use App\Filament\Client\Pages\Order as OrderPage;
use App\Filament\Client\Pages\SearchDomains;
use App\Filament\Clusters\Settings\Pages\DomainSettings;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Product;
use App\Models\Registrar;
use App\Models\User;
use App\Support\DomainSearch;
use App\Support\DomainSsl;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/** Settings → Domains → SSL with domains: a certificate offered with a domain ordered on its own. */
class DomainSslTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Product $ssl;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        // Email Notifications, so the domain search asks no registrar.
        Registrar::factory()->withTlds()->create(['name' => 'Email Notifications', 'module' => 'email', 'credentials' => null]);
        $this->ssl = Product::factory()->create([
            'name' => 'PositiveSSL',
            'type' => ServiceType::Ssl,
            'package' => null,
            'billing_cycle' => BillingCycle::Annually,
            'prices' => ['annually' => ['enabled' => true, 'price_cents' => 1000, 'setup_cents' => 0]],
        ]);
        config(['gaiacp.domains.ssl_product' => $this->ssl->id, 'gaiacp.domains.ssl_discount' => 20]);

        $client = Client::factory()->create();
        $this->user = User::factory()->create(['role' => UserRole::Client, 'client_id' => $client->id, 'email' => $client->email]);
        Filament::setCurrentPanel('client');
        $this->actingAs($this->user, 'client');
    }

    public function test_the_certificate_is_offered_at_the_discount_on_its_first_period_only(): void
    {
        $item = DomainSsl::item('shop.gr', 'EUR');
        $this->assertEquals(['price_cents' => 1000, 'first_payment_cents' => 800, 'domain' => 'shop.gr'], array_intersect_key($item, array_flip(['price_cents', 'first_payment_cents', 'domain'])));
        $this->assertSame(BillingCycle::Annually, $item['billing_cycle']);

        config(['gaiacp.domains.ssl_discount' => 0]);
        $this->assertNull(DomainSsl::item('shop.gr', 'EUR')['first_payment_cents']);

        // Not offered: none set, or a product clients can't order
        $this->ssl->update(['hidden' => true]);
        $this->assertNull(DomainSsl::product());
        config(['gaiacp.domains.ssl_product' => null]);
        $this->assertNull(DomainSsl::item('shop.gr', 'EUR'));
    }

    public function test_a_domain_ordered_with_ssl_makes_both_services_on_one_notice(): void
    {
        Livewire::test(OrderPage::class)
            ->fillForm(['product' => 'domain', 'domain_option' => 'register', 'domain' => 'shop.gr', 'years' => 1])
            ->assertFormFieldVisible('ssl')
            ->assertSee('€8.00 with the domain, then €10.00 (Annually)')
            ->fillForm(['ssl' => true])
            ->checkout()
            ->assertHasNoErrors();

        $services = Order::query()->sole()->services()->orderBy('id')->get();
        $this->assertSame([ServiceType::Domain, ServiceType::Ssl], $services->pluck('type')->all());
        $ssl = $services[1];
        $this->assertSame(['shop.gr', 1000, 800, $this->ssl->id], [$ssl->domain, $ssl->price_cents, $ssl->first_payment_cents, $ssl->product_id]);
        $this->assertSame([1500, 800], Invoice::query()->sole()->items->pluck('amount_cents')->all());
    }

    public function test_hosting_orders_and_a_setting_without_product_offer_no_certificate(): void
    {
        $hosting = Product::factory()->create(['prices' => ['annually' => ['enabled' => true, 'price_cents' => 9000, 'setup_cents' => 0]]]);

        Livewire::test(OrderPage::class)
            ->fillForm(['product' => (string) $hosting->id, 'domain_option' => 'register', 'domain' => 'shop.gr'])
            ->assertFormFieldHidden('ssl');

        config(['gaiacp.domains.ssl_product' => null]);
        Livewire::test(OrderPage::class)
            ->fillForm(['product' => 'domain', 'domain_option' => 'register', 'domain' => 'shop.gr'])
            ->assertFormFieldHidden('ssl');

        // A cart item kept from before the setting was cleared says so.
        $this->expectException(ValidationException::class);
        app(ClientOrder::class)->items(['product' => 'domain', 'domain_option' => 'register', 'domain' => 'shop.gr', 'years' => 1, 'ssl' => true]);
    }

    public function test_the_domain_search_adds_a_domain_with_its_certificate(): void
    {
        Http::fake([DomainSearch::BOOTSTRAP_URL => Http::response(['services' => []])]);
        $page = Livewire::test(SearchDomains::class)->set('query', 'shop')->call('search')
            ->assertSee('With SSL: €23.00'); // .gr 15.00 + certificate 8.00

        $page->call('add', 'shop.gr', true);
        $this->assertTrue(collect(ClientOrder::cart())->sole()['ssl']);
        $line = app(ClientOrder::class)->describe(collect(ClientOrder::cart())->sole());
        $this->assertSame(['shop.gr', 2300], [$line['title'], $line['amount_cents']]);
        $this->assertContains('With PositiveSSL, Annually', $line['details']);
    }

    public function test_settings_pick_the_certificate_and_its_discount(): void
    {
        config(['gaiacp.domains.ssl_product' => null, 'gaiacp.domains.ssl_discount' => 0]);
        $this->actingAs(User::factory()->create());
        Filament::setCurrentPanel('admin');

        Livewire::test(DomainSettings::class)
            ->fillForm(['domains.ssl_product' => $this->ssl->id, 'domains.ssl_discount' => 150])
            ->call('save')
            ->assertHasFormErrors(['domains.ssl_discount']);

        Livewire::test(DomainSettings::class)
            ->fillForm(['domains.ssl_product' => $this->ssl->id, 'domains.ssl_discount' => 25])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($this->ssl->id, config('gaiacp.domains.ssl_product'));
        $this->assertSame(25, config('gaiacp.domains.ssl_discount'));
    }
}
