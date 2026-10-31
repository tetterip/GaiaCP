<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Client\Pages\WhoisLookup;
use App\Models\Client;
use App\Models\User;
use App\Support\DomainSearch;
use App\Support\Whois;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class WhoisTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('client');
        Http::fake([
            DomainSearch::BOOTSTRAP_URL => Http::response(['services' => [[['com'], ['https://rdap.example/com/v1/']]]]),
            'rdap.example/com/v1/domain/taken.com' => Http::response([
                'ldhName' => 'TAKEN.COM',
                'status' => ['client transfer prohibited', 'active'],
                'events' => [
                    ['eventAction' => 'registration', 'eventDate' => '2015-03-04T10:00:00Z'],
                    ['eventAction' => 'expiration', 'eventDate' => '2027-03-04T10:00:00Z'],
                ],
                'entities' => [
                    ['roles' => ['registrar'], 'vcardArray' => ['vcard', [['version', [], 'text', '4.0'], ['fn', [], 'text', 'Example Registrar, Inc.']]]],
                    ['roles' => ['registrant'], 'vcardArray' => ['vcard', [['fn', [], 'text', 'Secret Owner']]]],
                ],
                'nameservers' => [['ldhName' => 'NS1.HOST.EXAMPLE'], ['ldhName' => 'ns2.host.example.']],
                'secureDNS' => ['delegationSigned' => false],
            ]),
            'rdap.example/*' => Http::response(['errorCode' => 404], 404),
        ]);
        Whois::queryUsing(fn (string $server, string $query) => match ([$server, $query]) {
            [Whois::IANA, 'gr'] => "domain: GR\nwhois:        whois.example.gr\n",
            ['whois.example.gr', 'taken.gr'] => "Domain: taken.gr\nRegistrar: Some registrar\n",
            ['whois.example.gr', 'free.gr'] => "No match for free.gr\n",
            default => null,
        });
    }

    protected function tearDown(): void
    {
        Whois::queryUsing(null);

        parent::tearDown();
    }

    public function test_the_page_is_off_until_switched_on(): void
    {
        $this->get(WhoisLookup::getUrl())->assertForbidden();

        config(['gaiacp.public_area.whois' => true]);
        $this->get(WhoisLookup::getUrl())->assertOk()->assertSee('WHOIS lookup');
    }

    public function test_a_visitor_sees_the_registrar_dates_and_nameservers_never_the_owner(): void
    {
        config(['gaiacp.public_area.whois' => true, 'gaiacp.region.date_format' => 'd/m/Y']);

        Livewire::test(WhoisLookup::class)
            ->set('query', 'https://www.Taken.com/page')
            ->call('lookup')
            ->assertHasNoErrors()
            ->assertSet('query', 'taken.com')
            ->assertSee('Example Registrar, Inc.')
            ->assertSee('04/03/2015')
            ->assertSee('04/03/2027')
            ->assertSee('ns1.host.example')
            ->assertSee('ns2.host.example')
            ->assertSee('Client transfer prohibited')
            ->assertSee('Not signed')
            ->assertDontSee('Secret Owner');

        Livewire::test(WhoisLookup::class)->set('query', 'free.com')->call('lookup')->assertSee('free.com is not registered.');
        Livewire::test(WhoisLookup::class)->set('query', 'not a domain')->call('lookup')->assertHasErrors(['query']);
    }

    public function test_a_tld_without_rdap_shows_its_whois_servers_answer(): void
    {
        config(['gaiacp.public_area.whois' => true]);

        Livewire::test(WhoisLookup::class)->set('query', 'taken.gr')->call('lookup')
            ->assertSee('Registrar: Some registrar');
        Livewire::test(WhoisLookup::class)->set('query', 'free.gr')->call('lookup')
            ->assertSee('free.gr is not registered.');
        Livewire::test(WhoisLookup::class)->set('query', 'taken.xyz')->call('lookup')
            ->assertSet('failed', true)->assertSee('could not be asked');
    }

    public function test_lookups_are_limited_and_a_client_sees_it_in_the_menu(): void
    {
        config(['gaiacp.public_area.whois' => true]);
        $page = Livewire::test(WhoisLookup::class);

        foreach (range(1, Whois::PER_MINUTE) as $i) {
            $page->set('query', 'free.com')->call('lookup')->assertHasNoErrors();
        }
        $page->call('lookup')->assertHasErrors(['query']);

        $client = Client::factory()->create();
        $this->actingAs(User::factory()->create(['role' => UserRole::Client, 'client_id' => $client->id, 'email' => $client->email]), 'client')->get('/services')->assertOk()->assertSee(WhoisLookup::getUrl());
    }
}
