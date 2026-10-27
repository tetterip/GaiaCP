<?php

namespace Tests\Feature;

use App\Actions\ClientOrder;
use App\Enums\UserRole;
use App\Filament\Client\Pages\DomainPrices;
use App\Filament\Client\Pages\PublicOrder;
use App\Filament\Client\Pages\SearchDomains;
use App\Filament\Client\Resources\Services\ServiceResource;
use App\Filament\Client\Resources\Tickets\TicketResource;
use App\Filament\Clusters\Settings\Pages\PublicAreaSettings;
use App\Models\Client;
use App\Models\Product;
use App\Models\Registrar;
use App\Models\Service;
use App\Models\Ticket;
use App\Models\Tld;
use App\Models\User;
use App\Support\DomainSearch;
use App\Support\PublicArea;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/** The public area (Settings → Public area): home page, domain search and domain prices for visitors, each behind a switch. */
class PublicAreaTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config(['gaiacp.company.name' => 'Acme Hosting']);
        Product::factory()->create([
            'name' => 'Hosting Business',
            'prices' => ['annually' => ['enabled' => true, 'price_cents' => 9000, 'setup_cents' => 0]],
        ]);
        Registrar::factory()->withTlds()->create(['name' => 'Email Notifications', 'module' => 'email', 'credentials' => null]);
        Tld::query()->where('tld', '.gr')->update(['register_1y_cents' => null, 'renew_1y_cents' => null, 'register_2y_cents' => 2400, 'renew_2y_cents' => 2400, 'transfer_2y_cents' => 0]);

        $client = Client::factory()->create();
        $this->user = User::factory()->create(['role' => UserRole::Client, 'client_id' => $client->id, 'email' => $client->email]);
    }

    public function test_everything_is_off_by_default_and_the_root_stays_the_login(): void
    {
        $this->get('/')->assertRedirect('/login');
        $this->get('/domain-search')->assertForbidden();
        $this->get('/domain-prices')->assertForbidden();
        $this->get('/dashboard')->assertRedirect('/login');
        $this->get('/login')->assertOk()->assertDontSee('class="gcp-public-back"', false);
    }

    public function test_the_home_page_shows_the_welcome_what_is_sold_and_the_way_in(): void
    {
        config([
            'gaiacp.public_area.home' => true,
            'gaiacp.public_area.welcome_text' => ['en' => 'Hosting with **real people**.'],
            'gaiacp.client_area.signup' => true,
        ]);

        $this->get('/')->assertOk()
            ->assertSee('Acme Hosting')
            ->assertSee('Hosting with <strong>real people</strong>.', false)
            ->assertSeeInOrder(['Hosting', 'Hosting Business', 'From €7.50 / month', __('Domain names')])
            ->assertSee(__('Log in'))
            ->assertSee(__('Sign up'))
            ->assertDontSee(__('Domain prices'));

        // The login links back to it
        $this->get('/login')->assertOk()->assertSee('class="gcp-public-back"', false);
    }

    public function test_products_link_to_the_public_order_page_while_it_is_on_else_to_sign_up(): void
    {
        config(['gaiacp.public_area.home' => true, 'gaiacp.client_area.signup' => true]);
        $product = Product::query()->sole();

        $this->get('/')->assertOk()->assertSee('/signup')->assertDontSee('/cart?product=');

        config(['gaiacp.client_area.public_orders' => true]);
        $this->get('/')->assertOk()->assertSee('/cart?product='.$product->id, false);

        // Nothing to order while clients can't
        config(['gaiacp.client_area.orders' => false]);
        $this->get('/')->assertOk()->assertDontSee('Hosting Business')->assertDontSee(__('Domain names'));
    }

    public function test_signed_in_clients_go_to_their_own_home(): void
    {
        config(['gaiacp.public_area.home' => true]);

        $this->actingAs($this->user, 'client')->get('/')->assertRedirect('/dashboard');
    }

    public function test_the_welcome_falls_back_to_the_default_language_then_the_company_name(): void
    {
        config(['gaiacp.public_area.welcome_title' => ['el' => 'Καλώς ήρθατε']]);
        $this->assertSame('Acme Hosting', PublicArea::welcomeTitle());

        app()->setLocale('el');
        $this->assertSame('Καλώς ήρθατε', PublicArea::welcomeTitle());

        config(['gaiacp.public_area.welcome_title' => ['en' => 'Welcome']]);
        $this->assertSame('Welcome', PublicArea::welcomeTitle());
    }

    public function test_visitors_pick_their_language(): void
    {
        config(['gaiacp.public_area.home' => true]);

        $this->get('/')->assertSee(__('Log in'));
        $this->get('/language/el')->assertRedirect();
        $this->get('/')->assertSee('Σύνδεση');
        $this->get('/language/xx')->assertNotFound();
    }

    public function test_visitors_switch_between_light_and_dark_mode(): void
    {
        config(['gaiacp.public_area.home' => true]);

        // The same three choices clients have in their menu
        $this->get('/')->assertOk()->assertSee('gcp-public-theme-btn', false)->assertSee('fi-theme-switcher', false);
    }

    public function test_the_side_menu_lists_only_what_is_on(): void
    {
        config(['gaiacp.public_area.home' => true]);
        $this->get('/')->assertOk()->assertSee('fi-sidebar', false)->assertDontSee('/domain-search');

        config(['gaiacp.public_area.domain_search' => true, 'gaiacp.public_area.domain_prices' => true, 'gaiacp.client_area.public_orders' => true]);
        $this->get('/')->assertOk()->assertSeeInOrder([__('Home'), __('Order'), __('Domain search'), __('Domain prices'), __('Your account'), __('Log in')]);
    }

    public function test_domain_prices_lists_each_tld_for_its_shortest_term(): void
    {
        config(['gaiacp.public_area.domain_prices' => true]);

        $this->get('/domain-prices')->assertOk()
            ->assertSeeInOrder(['.com', '€15.00', '.com.gr', '.gr', '€24.00 for 2 years', __('Free'), '€24.00 for 2 years']);

        // Signed-in clients see it too
        $this->actingAs($this->user, 'client')->get('/domain-prices')->assertOk()->assertSee('.com.gr');
    }

    public function test_visitors_search_for_a_domain_and_add_it_while_the_public_order_page_is_on(): void
    {
        config(['gaiacp.public_area.domain_search' => true]);
        Http::fake([
            DomainSearch::BOOTSTRAP_URL => Http::response(['services' => [[['com'], ['https://rdap.example/com/v1/']]]]),
            'rdap.example/com/v1/domain/taken.com' => Http::response(['ldhName' => 'TAKEN.COM']),
            'rdap.example/*' => Http::response(['errorCode' => 404], 404),
        ]);
        DomainSearch::resolveNameserversUsing(fn () => []);
        Filament::setCurrentPanel('client');

        $this->get('/domain-search')->assertOk();

        $page = Livewire::test(SearchDomains::class)->set('query', 'free.com')->call('search')
            ->assertSee('free.com')->assertSee('€15.00')->assertDontSee(__('Add to cart'));
        $page->call('add', 'free.com');
        $this->assertSame(0, ClientOrder::cartCount());

        config(['gaiacp.client_area.public_orders' => true]);
        $page = Livewire::test(SearchDomains::class)->set('query', 'free.com')->call('search')->assertSee(__('Add to cart'));
        $page->call('add', 'free.com');
        $this->assertSame(1, ClientOrder::cartCount());
        $this->assertSame(route('filament.client.cart'), $page->instance()->cartUrl());

        // A taken one opens the public order page on its transfer
        $this->assertSame(route('filament.client.cart', ['product' => 'domain', 'domain_option' => 'transfer', 'domain' => 'taken.com']), $page->instance()->transferUrl('taken.com'));
        Livewire::withQueryParams(['product' => 'domain', 'domain_option' => 'transfer', 'domain' => 'taken.com'])
            ->test(PublicOrder::class)
            ->assertSchemaStateSet(['product' => 'domain', 'domain_option' => 'transfer', 'domain' => 'taken.com']);
    }

    public function test_visitors_never_see_records_without_a_client(): void
    {
        config(['gaiacp.public_area.home' => true]);
        Ticket::query()->create(['client_id' => null, 'email' => 'someone@example.com', 'subject' => 'From an unknown sender']);
        Service::factory()->create();
        Filament::setCurrentPanel('client');

        $this->assertSame(0, TicketResource::getEloquentQuery()->count());
        $this->assertSame(0, ServiceResource::getEloquentQuery()->count());
        $this->assertFalse(ServiceResource::canGloballySearch());
        $this->get('/')->assertOk()->assertDontSee('class="fi-global-search-ctn', false);
    }

    public function test_the_settings_page_saves_the_switches_and_texts(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(PublicAreaSettings::class)
            ->set('data.public_area.home', true)
            ->set('data.public_area.welcome_title.en', '  Welcome  ')
            ->set('data.public_area.domain_prices', true)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertTrue(config('gaiacp.public_area.home'));
        $this->assertFalse(config('gaiacp.public_area.domain_search'));
        $this->assertTrue(config('gaiacp.public_area.domain_prices'));
        $this->assertSame(['en' => 'Welcome'], config('gaiacp.public_area.welcome_title'));
        $this->assertSame([], config('gaiacp.public_area.welcome_text'));
        $this->assertTrue(DomainPrices::canAccess());
    }
}
