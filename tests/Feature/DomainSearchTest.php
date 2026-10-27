<?php

namespace Tests\Feature;

use App\Actions\ClientOrder;
use App\Enums\DomainAvailability;
use App\Enums\UserRole;
use App\Filament\Client\Pages\Dashboard;
use App\Filament\Client\Pages\Order as OrderPage;
use App\Filament\Client\Pages\PublicOrder;
use App\Filament\Client\Pages\SearchDomains;
use App\Filament\Client\Resources\Domains\Pages\ListDomains;
use App\Filament\Resources\Orders\Pages\CreateOrder;
use App\Models\Client;
use App\Models\Registrar;
use App\Models\Service;
use App\Models\Tld;
use App\Models\User;
use App\Support\DomainSearch;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Plugins\Turnstile\TurnstilePlugin;
use Tests\FakesEasy;
use Tests\FakesEnom;
use Tests\TestCase;

/** Domains → Search for a domain: the name with each TLD sold, free or taken, and Add to cart. */
class DomainSearchTest extends TestCase
{
    use FakesEasy, FakesEnom, RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $client = Client::factory()->create();
        $this->user = User::factory()->create(['role' => UserRole::Client, 'client_id' => $client->id, 'email' => $client->email]);
    }

    /** .gr, .com and .com.gr sold; RDAP answers for .com (taken.com is registered), .gr has none. */
    private function fakeRegistry(): void
    {
        // Email Notifications: a registrar that can't tell, so RDAP and DNS answer.
        Registrar::factory()->withTlds()->create(['name' => 'Email Notifications', 'module' => 'email', 'credentials' => null]);
        Tld::query()->where('tld', '.gr')->update(['register_1y_cents' => null, 'register_2y_cents' => 2400, 'transfer_2y_cents' => 2400]);

        Http::fake([
            DomainSearch::BOOTSTRAP_URL => Http::response(['services' => [[['com', 'net'], ['https://rdap.example/com/v1/']]]]),
            'rdap.example/com/v1/domain/taken.com' => Http::response(['ldhName' => 'TAKEN.COM']),
            'rdap.example/*' => Http::response(['errorCode' => 404], 404),
        ]);
        DomainSearch::resolveNameserversUsing(fn (string $domain) => $domain === 'taken.gr' ? ['ns1.host.gr'] : []);
    }

    private function signIn(): void
    {
        Filament::setCurrentPanel('client');
        $this->actingAs($this->user, 'client');
    }

    public function test_the_name_is_looked_for_with_each_tld_sold_the_typed_one_first(): void
    {
        Registrar::factory()->withTlds()->create();
        Tld::query()->create(['tld' => '.eu', 'transfer_1y_cents' => 900]); // transfers only

        $this->assertSame(['myshop.gr', 'myshop.com', 'myshop.com.gr'], DomainSearch::candidates('MyShop')['domains']);
        $this->assertSame(['myshop.com', 'myshop.gr', 'myshop.com.gr'], DomainSearch::candidates('https://www.myshop.com/about')['domains']);
        $this->assertSame('myshop.com.gr', DomainSearch::candidates('myshop.com.gr')['requested']);

        $other = DomainSearch::candidates('myshop.xyz');
        $this->assertSame('.xyz', $other['unsupported']);
        $this->assertSame(['myshop.gr', 'myshop.com', 'myshop.com.gr'], $other['domains']);
        $this->assertSame('.eu', DomainSearch::candidates('myshop.eu')['unsupported']);

        $this->assertSame([], DomainSearch::candidates('my shop')['domains']);
        $this->assertSame([], DomainSearch::candidates('-shop')['domains']);
    }

    public function test_rdap_then_dns_say_whether_it_is_free_and_the_answer_is_kept(): void
    {
        $this->fakeRegistry();
        $search = app(DomainSearch::class);

        $this->assertSame(DomainAvailability::Taken, $search->check('taken.com'));
        $this->assertSame(DomainAvailability::Available, $search->check('free.com'));
        $this->assertSame(DomainAvailability::Taken, $search->check('taken.gr')); // no RDAP: it has nameservers
        $this->assertSame(DomainAvailability::Unknown, $search->check('free.gr'));

        $sent = count(Http::recorded());
        $this->assertSame(DomainAvailability::Available, $search->check('free.com'));
        $this->assertCount($sent, Http::recorded());
        $this->assertCount(1, Http::recorded(fn (Request $request) => $request->url() === DomainSearch::BOOTSTRAP_URL));
    }

    public function test_a_registry_that_does_not_answer_falls_back_to_dns(): void
    {
        Registrar::factory()->withTlds(['.com'])->create(['module' => 'email', 'credentials' => null]);
        Http::fake([
            DomainSearch::BOOTSTRAP_URL => Http::response(['services' => [[['com'], ['https://rdap.example/com/v1/']]]]),
            'rdap.example/*' => Http::response('', 429),
        ]);

        $this->assertSame(DomainAvailability::Unknown, app(DomainSearch::class)->check('busy.com'));
    }

    public function test_a_registrar_that_can_tell_is_asked_first(): void
    {
        $this->fakeEnom();
        $this->enomDomains['taken.com'] = ['expires' => '2027-01-01'];

        $this->assertSame(DomainAvailability::Taken, app(DomainSearch::class)->check('taken.com'));
        $this->assertSame(DomainAvailability::Available, app(DomainSearch::class)->check('free.com'));
        $this->assertSame([['Check', ['SLD' => 'taken', 'TLD' => 'com']], ['Check', ['SLD' => 'free', 'TLD' => 'com']]], $this->enomCalls);
    }

    public function test_easy_gr_answers_for_its_tlds(): void
    {
        $this->fakeEasy();
        $this->easyDomains['taken.gr'] = ['expires' => '2027-01-01'];

        $this->assertSame(DomainAvailability::Taken, app(DomainSearch::class)->check('taken.gr'));
        $this->assertSame(DomainAvailability::Available, app(DomainSearch::class)->check('free.gr'));
        $this->assertSame([[0, ['domain' => 'taken.gr']], [0, ['domain' => 'free.gr']]], $this->easyCalls);
        Http::assertSent(fn (Request $request) => $request->method() === 'GET' && str_starts_with($request->url(), 'https://api.easy.gr'));
    }

    public function test_an_easy_gr_error_is_not_read_as_taken(): void
    {
        $this->fakeEasy();
        $this->easyFailures[0] = ['1001' => 'Wrong username or password'];

        // easy.gr refused, there is no RDAP for .gr and no nameservers: probably free, never "taken".
        $this->assertSame(DomainAvailability::Unknown, app(DomainSearch::class)->check('maybe.gr'));
    }

    public function test_the_client_searches_and_adds_a_free_domain_to_the_cart(): void
    {
        $this->fakeRegistry();
        $this->signIn();

        $page = Livewire::test(SearchDomains::class)
            ->set('query', 'free')
            ->call('search')
            ->assertSee('free.gr')
            ->assertSee('€24.00 for 2 years')
            ->assertSee(__('Probably available'))
            ->assertSee('free.com.gr');

        $page->call('add', 'free.gr')->assertNotified()->assertSee(__('In your cart'));

        $line = collect(ClientOrder::cart())->sole();
        $this->assertSame(['product' => 'domain', 'domain_option' => 'register', 'domain' => 'free.gr', 'years' => 2], $line);
        $page->call('add', 'free.gr');
        $this->assertSame(1, ClientOrder::cartCount()); // already in it
    }

    public function test_a_taken_domain_offers_a_transfer_instead(): void
    {
        $this->fakeRegistry();
        $this->signIn();

        $page = Livewire::test(SearchDomains::class)->set('query', 'taken.gr')->call('search');
        $this->assertSame('taken', $page->get('results')[0]['status']);
        $page->assertSee(__('Mine: transfer it'))->call('add', 'taken.gr');
        $this->assertSame(0, ClientOrder::cartCount());

        // The Order page opens on the transfer, for the TLD's first transfer term.
        Livewire::withQueryParams(['product' => 'domain', 'domain_option' => 'transfer', 'domain' => 'taken.gr'])
            ->test(OrderPage::class)
            ->assertSchemaStateSet(['product' => 'domain', 'domain_option' => 'transfer', 'domain' => 'taken.gr', 'years' => 2]);
    }

    public function test_the_captcha_is_asked_before_the_first_search_then_for_a_while_not(): void
    {
        config([
            'gaiacp.turnstile.site_key' => 'ts-site', 'gaiacp.turnstile.secret_key' => 'ts-secret',
            'gaiacp.captcha.provider' => 'turnstile', 'gaiacp.captcha.forms' => ['domain_search'],
        ]);
        Http::fake([TurnstilePlugin::VERIFY_URL => Http::sequence()->push(['success' => false])->push(['success' => true])]);
        $this->fakeRegistry();
        $this->signIn();

        // A link with ?q= waits for the captcha instead of searching.
        Livewire::withQueryParams(['q' => 'free'])->test(SearchDomains::class)->assertSet('results', [])->assertSee('ts-site');

        $page = Livewire::test(SearchDomains::class)->set('query', 'free');
        $page->call('search')->assertHasErrors(['captcha'])->assertSet('results', []);
        $page->set('captcha', 'bad')->call('search')->assertHasErrors(['captcha'])->assertDispatched('captcha-reset');
        $page->set('captcha', 'good')->call('search')->assertHasNoErrors()->assertSee('free.com')->assertDontSee('ts-site');

        // Solved: the next searches need none, until VERIFIED_MINUTES pass.
        $page->set('query', 'other')->call('search')->assertHasNoErrors()->assertSee('other.com');
        $this->travel(SearchDomains::VERIFIED_MINUTES + 1)->minutes();
        $page->set('query', 'third')->call('search')->assertHasErrors(['captcha']);
        Http::assertSentCount(5); // two captcha checks, IANA's list, free.com and other.com; third was never looked up
    }

    public function test_bad_names_and_too_many_searches_are_refused(): void
    {
        $this->fakeRegistry();
        $this->signIn();

        Livewire::test(SearchDomains::class)->set('query', 'my shop!')->call('search')->assertHasErrors('query');

        $page = Livewire::test(SearchDomains::class)->set('query', 'free');
        foreach (range(1, SearchDomains::PER_MINUTE) as $i) {
            $page->call('search')->assertHasNoErrors();
        }
        $page->call('search')->assertHasErrors('query');
    }

    public function test_the_page_shows_while_clients_can_order_a_domain_and_the_domains_list_links_to_it(): void
    {
        $this->signIn();
        $this->assertFalse(SearchDomains::canAccess()); // no TLD sold

        $this->fakeRegistry();
        $this->assertTrue(SearchDomains::canAccess());
        $this->get(SearchDomains::getUrl(['q' => 'free'], panel: 'client'))->assertOk()->assertSee('free.com');

        // In the menu on every page (Home here), right after Domains and before Payment notices.
        $this->get(Dashboard::getUrl(panel: 'client'))->assertOk()
            ->assertSeeInOrder([__('Domains'), __('Domain search'), __('Payment notices')]);

        Service::factory()->domain()->create(['client_id' => $this->user->client_id]);
        Livewire::test(ListDomains::class)->assertActionHasUrl('orderDomain', SearchDomains::getUrl());

        config(['gaiacp.client_area.orders' => false]);
        $this->assertFalse(SearchDomains::canAccess());
    }

    public function test_the_order_page_says_whether_the_domain_is_free_and_refuses_a_taken_one(): void
    {
        $this->fakeRegistry();
        Tld::query()->where('tld', '.com')->update(['transfer_1y_cents' => 1200]);
        $this->signIn();

        $page = Livewire::test(OrderPage::class)
            ->fillForm(['product' => 'domain', 'domain_option' => 'register', 'domain' => 'taken.com', 'years' => 1])
            ->assertSee(__('Taken'));
        $page->call('addToCart')->assertHasErrors(['data.domain']);
        $this->assertSame(0, ClientOrder::cartCount());

        // Mine after all: a transfer is fine; one not registered yet can't be transferred.
        $page->fillForm(['domain_option' => 'transfer', 'epp_code' => 'abc'])->assertDontSee(__('Not registered yet: register it instead'))
            ->call('addToCart')->assertHasNoErrors();
        Livewire::test(OrderPage::class)
            ->fillForm(['product' => 'domain', 'domain_option' => 'transfer', 'domain' => 'free.com', 'years' => 1, 'epp_code' => 'abc'])
            ->assertSee(__('Not registered yet: register it instead'))
            ->call('addToCart')->assertHasErrors(['data.domain']);

        Livewire::test(OrderPage::class)
            ->fillForm(['product' => 'domain', 'domain_option' => 'register', 'domain' => 'free.com', 'years' => 1])
            ->assertSee(__('Available'))
            ->call('addToCart')->assertHasNoErrors();
        $this->assertSame(2, ClientOrder::cartCount());
    }

    public function test_visitors_see_it_on_the_public_order_page_within_the_lookup_limit(): void
    {
        config(['gaiacp.client_area.public_orders' => true]);
        $this->fakeRegistry();
        Filament::setCurrentPanel('client');

        Livewire::test(PublicOrder::class)
            ->fillForm(['product' => 'domain', 'domain_option' => 'register', 'domain' => 'taken.com', 'years' => 1])
            ->assertSee(__('Taken'));

        // Past the limit nothing is looked up (answers already known still show).
        foreach (range(1, DomainSearch::PER_MINUTE) as $i) {
            DomainSearch::forOrder("name{$i}.com");
        }
        $sent = count(Http::recorded());
        Livewire::test(PublicOrder::class)
            ->fillForm(['product' => 'domain', 'domain_option' => 'register', 'domain' => 'another.com', 'years' => 1])
            ->assertDontSee(__('Available'));
        $this->assertCount($sent, Http::recorded());
    }

    public function test_a_visitors_hint_never_asks_the_registrar(): void
    {
        $this->fakeEnom();
        Http::fake([DomainSearch::BOOTSTRAP_URL => Http::response(['services' => []])]);
        DomainSearch::resolveNameserversUsing(fn () => []);

        $this->assertSame(DomainAvailability::Unknown, DomainSearch::forOrder('visitor.com'));
        $this->assertSame([], $this->enomCalls);

        $this->signIn();
        $this->assertSame(DomainAvailability::Available, DomainSearch::forOrder('client.com'));
        $this->assertSame([['Check', ['SLD' => 'client', 'TLD' => 'com']]], $this->enomCalls);
    }

    public function test_admins_see_it_on_new_order(): void
    {
        $this->fakeRegistry();
        $this->actingAs(User::factory()->create());
        Filament::setCurrentPanel('admin');

        Livewire::test(CreateOrder::class)
            ->fillForm(['client_id' => $this->user->client_id, 'items' => [['type' => 'domain', 'domain' => 'taken.com', 'registration_type' => 'register', 'years' => 1]]])
            ->assertSee(__('Taken'));
    }
}
