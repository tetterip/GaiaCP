<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Client\Pages\DomainPrices;
use App\Filament\Client\Pages\Help;
use App\Filament\Client\Pages\PublicHome;
use App\Filament\Client\Pages\SearchDomains;
use App\Filament\Clusters\Settings\Pages\DomainSettings;
use App\Models\Client;
use App\Models\Registrar;
use App\Models\Tld;
use App\Models\User;
use App\Support\DomainSearch;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/** Settings → Domains → Spotlight TLDs: checked first in the domain search and shown as cards, with their prices under the box. */
class SpotlightTldsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // .gr, .com, .com.gr, .eu sold (in that order under Domain pricing); RDAP answers: taken.com is registered
        Registrar::factory()->withTlds(['.com.gr', '.eu', '.com', '.gr'])->create(['name' => 'Email Notifications', 'module' => 'email', 'credentials' => null]);
        Tld::query()->where('tld', '.gr')->update(['register_1y_cents' => null, 'register_2y_cents' => 2400]);
        Http::fake([
            DomainSearch::BOOTSTRAP_URL => Http::response(['services' => [[['com', 'eu'], ['https://rdap.example/v1/']]]]),
            'rdap.example/v1/domain/taken.com' => Http::response(['ldhName' => 'TAKEN.COM']),
            'rdap.example/*' => Http::response(['errorCode' => 404], 404),
        ]);
        DomainSearch::resolveNameserversUsing(fn (string $domain) => []);
    }

    private function signIn(): void
    {
        $client = Client::factory()->create();
        Filament::setCurrentPanel('client');
        $this->actingAs(User::factory()->create(['role' => UserRole::Client, 'client_id' => $client->id, 'email' => $client->email]), 'client');
    }

    public function test_the_admin_picks_them_in_order_from_the_tlds_offered(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(DomainSettings::class)
            ->fillForm(['domains' => ['spotlight' => ['GR', '.com', '.gr', 'com.gr']]])
            ->call('save')
            ->assertHasNoErrors();
        $this->assertSame(['.gr', '.com', '.com.gr'], config('gaiacp.domains.spotlight'));

        Livewire::test(DomainSettings::class)
            ->fillForm(['domains' => ['spotlight' => ['.gr', '.xyz']]])
            ->call('save')
            ->assertHasErrors();
        $this->assertSame(['.gr', '.com', '.com.gr'], config('gaiacp.domains.spotlight'));
    }

    public function test_the_search_checks_them_first_and_marks_them_with_the_domain_typed(): void
    {
        $this->assertSame(['myshop.com.gr', 'myshop.eu', 'myshop.com', 'myshop.gr'], DomainSearch::candidates('myshop')['domains']);
        $this->assertSame([], DomainSearch::candidates('myshop')['spotlight']);
        $this->assertFalse(Help::features()['domain_spotlight']);

        // .xyz is not sold and .eu is no longer offered: both left out
        Tld::query()->where('tld', '.eu')->update(['active' => false]);
        config(['gaiacp.domains.spotlight' => ['.gr', '.com', '.xyz', '.eu']]);

        $this->assertSame(['.gr', '.com'], array_map(fn (Tld $tld) => $tld->tld, DomainSearch::spotlight()));
        $this->assertTrue(Help::features()['domain_spotlight']); // the client guide's paragraph about them
        $found = DomainSearch::candidates('myshop');
        $this->assertSame(['myshop.gr', 'myshop.com', 'myshop.com.gr'], $found['domains']);
        $this->assertSame(['myshop.gr', 'myshop.com'], $found['spotlight']);

        // The domain typed comes first, as a card too
        $typed = DomainSearch::candidates('myshop.com.gr');
        $this->assertSame(['myshop.com.gr', 'myshop.gr', 'myshop.com'], $typed['domains']);
        $this->assertSame(['myshop.com.gr', 'myshop.gr', 'myshop.com'], $typed['spotlight']);
    }

    public function test_the_page_shows_them_as_cards_with_their_price_above_the_other_endings(): void
    {
        config(['gaiacp.domains.spotlight' => ['.gr', '.com']]);
        $this->signIn();

        Livewire::test(SearchDomains::class)
            ->assertSeeInOrder(['.gr', '€24.00 for 2 years', '.com', '€15.00 for 1 year']) // their prices before a search
            ->set('query', 'taken')
            ->call('search')
            ->assertSeeHtml('gcp-domain-card')
            ->assertSeeInOrder(['taken', '.gr', '24.00 for 2 years', 'taken', '.com', __('Taken'), __('Other endings:'), 'taken.com.gr', 'taken.eu'])
            ->call('add', 'taken.gr')
            ->assertSee(__('In your cart'));
    }

    public function test_without_them_the_search_is_a_plain_list(): void
    {
        $this->signIn();

        Livewire::test(SearchDomains::class)
            ->set('query', 'free')
            ->call('search')
            ->assertDontSeeHtml('gcp-domain-card')
            ->assertDontSee(__('Other endings:'))
            ->assertSee('free.com');
    }

    public function test_the_public_home_page_and_domain_prices_put_them_first(): void
    {
        config([
            'gaiacp.domains.spotlight' => ['.gr', '.com'],
            'gaiacp.public_area.home' => true, 'gaiacp.public_area.domain_search' => true, 'gaiacp.public_area.domain_prices' => true,
        ]);

        $this->get(PublicHome::getUrl(panel: 'client'))->assertOk()->assertSeeHtml('gcp-spotlight-prices')->assertSee('€24.00 for 2 years');
        $this->get(DomainPrices::getUrl(panel: 'client'))->assertOk()->assertSeeInOrder(['.gr', '.com', '.com.gr', '.eu']);
    }
}
