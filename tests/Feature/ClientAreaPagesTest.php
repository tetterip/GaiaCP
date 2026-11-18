<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Enums\UserRole;
use App\Filament\Client\Pages\Dashboard;
use App\Filament\Client\Resources\PaymentNotices\PaymentNoticeResource;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use App\Plugins\PluginManager;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\FakesWhm;
use Tests\TestCase;

/** Client area step 4: the client's own services, domains, payment notices and payments, read-only. */
class ClientAreaPagesTest extends TestCase
{
    use FakesWhm;
    use RefreshDatabase;

    private Client $client;

    private Client $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = Client::factory()->create(['first_name' => 'Maria', 'email' => 'maria@example.gr']);
        $this->other = Client::factory()->create();

        $user = User::factory()->create(['role' => UserRole::Client, 'client_id' => $this->client->id, 'email' => $this->client->email]);
        $this->actingAs($user, 'client');
    }

    private function notice(Client $client, array $attributes = []): Invoice
    {
        $notice = Invoice::create($attributes + [
            'number' => Invoice::nextNumber(now()),
            'client_id' => $client->id,
            'date' => now()->subDays(3),
            'due_date' => now()->addDays(10),
            'currency' => 'EUR',
            'subtotal_cents' => 6000,
            'tax_cents' => 0,
            'total_cents' => 6000,
        ]);

        $notice->items()->create([
            'description' => 'Web hosting example.gr',
            'due_date' => $notice->due_date,
            'period_end' => $notice->due_date->addYear(),
            'amount_cents' => 6000,
            'tax_cents' => 0,
            'total_cents' => 6000,
        ]);

        return $notice;
    }

    public function test_services_show_only_the_clients_own_ones_shown_in_the_client_area(): void
    {
        $hosting = Service::factory()->for($this->client)->create(['label' => 'Business hosting', 'domain' => 'maria.gr']);
        $maintenance = Service::factory()->maintenance()->for($this->client)->create(['label' => 'Website care']);
        $hidden = Service::factory()->for($this->client)->create(['label' => 'Private box', 'client_visible' => false]);
        $others = Service::factory()->for($this->other)->create(['label' => 'Someone else hosting']);
        Service::factory()->domain()->for($this->client)->create(['domain' => 'maria-domain.gr']);

        $this->get('/services')->assertOk()
            ->assertSee('Business hosting')
            ->assertSee('Website care')
            ->assertDontSee('Private box')
            ->assertDontSee('Someone else hosting')
            ->assertDontSee('maria-domain.gr');

        $this->get("/services/{$hosting->id}")->assertOk()->assertSee('maria.gr');
        $this->get("/services/{$maintenance->id}")->assertOk()->assertSee('Website care')
            ->assertDontSee('Change password')->assertDontSee('Log in to');
        $this->get("/services/{$hidden->id}")->assertRedirect('/services'); // not theirs: the list, as for a deleted one
        $this->get("/services/{$others->id}")->assertRedirect('/services'); // not theirs: the list, as for a deleted one
    }

    public function test_domains_show_expiry_and_nameservers(): void
    {
        $domain = Service::factory()->domain()->for($this->client)->create(['domain' => 'maria.gr']);
        $domain->registration()->update(['expires_at' => '2027-03-05', 'nameservers' => ['ns1.acme.gr', 'ns2.acme.gr']]);
        $others = Service::factory()->domain()->for($this->other)->create(['domain' => 'other-person.gr']);
        Service::factory()->for($this->client)->create(['label' => 'Business hosting']);

        $this->get('/domains')->assertOk()
            ->assertSee('maria.gr')
            ->assertSee('05/03/2027')
            ->assertDontSee('other-person.gr')
            ->assertDontSee('Business hosting');

        $this->get("/domains/{$domain->id}")->assertOk()->assertSee('ns1.acme.gr')->assertSee('ns2.acme.gr');
        $this->get("/domains/{$others->id}")->assertRedirect('/domains'); // not theirs: the list, as for a deleted one
    }

    public function test_a_payment_notice_shows_its_lines_how_to_pay_and_that_it_is_not_an_invoice(): void
    {
        config(['gaiacp.payment_instructions' => 'Pay to IBAN GR16 0110 1250 0000 0001 2300 695']);
        $notice = $this->notice($this->client);
        $cancelled = $this->notice($this->client, ['status' => InvoiceStatus::Cancelled]);
        $others = $this->notice($this->other);

        $this->get('/payment-notices')->assertOk()
            ->assertSee($notice->number)
            ->assertDontSee($cancelled->number)
            ->assertDontSee($others->number);

        $this->get("/payment-notices/{$notice->id}")->assertOk()
            ->assertSee($notice->number)
            ->assertSee('Web hosting example.gr')
            ->assertSee('IBAN GR16 0110 1250 0000 0001 2300 695')
            ->assertSee('This is a payment notice, not a tax invoice.')
            ->assertSee('Print or save as PDF');

        $this->get("/payment-notices/{$others->id}")->assertRedirect('/payment-notices'); // not theirs: the list, as for a deleted one
        $this->get("/payment-notices/{$cancelled->id}")->assertRedirect('/payment-notices'); // not theirs: the list, as for a deleted one
    }

    public function test_a_paid_notice_leaves_out_how_to_pay(): void
    {
        config(['gaiacp.payment_instructions' => 'Pay to IBAN GR16']);
        $notice = $this->notice($this->client, ['status' => InvoiceStatus::Paid, 'paid_on' => now()]);

        $this->get("/payment-notices/{$notice->id}")->assertOk()->assertDontSee('IBAN GR16');
    }

    public function test_payments_list_the_clients_own_payments(): void
    {
        $service = Service::factory()->for($this->client)->create(['label' => 'Business hosting']);
        Payment::factory()->for($service)->create(['amount_cents' => 6000, 'paid_on' => '2026-09-01']);
        Payment::factory()->for($service)->create(['amount_cents' => 0, 'paid_on' => '2026-08-15']);
        Payment::factory()->for(Service::factory()->for($this->other)->create())->create(['amount_cents' => 4321, 'paid_on' => '2026-07-07']);

        $this->get('/payments')->assertOk()
            ->assertSee('01/09/2026')
            ->assertDontSee('15/08/2026')
            ->assertDontSee('07/07/2026');
    }

    public function test_home_shows_what_is_owed_and_the_next_renewals(): void
    {
        $this->notice($this->client, ['total_cents' => 7440]);
        Service::factory()->for($this->client)->dueOn(now()->addMonth()->toDateString())->create(['label' => 'Business hosting']);
        Service::factory()->maintenance()->for($this->client)->dueOn(now()->addWeek()->toDateString())->create(['label' => 'Website care']);

        $this->travelTo(now()->setTime(13, 0));

        $this->get('/dashboard')->assertOk()
            ->assertSee('Good afternoon, Maria')
            ->assertSee(now()->translatedFormat('l j F'))
            ->assertDontSee('Welcome, Maria')
            ->assertSee('74.40')
            ->assertSee('1 payment notice')
            ->assertSee('Business hosting')
            ->assertSee('Website care');
    }

    public function test_home_counts_the_active_services_per_kind_and_links_each_list(): void
    {
        Service::factory()->for($this->client)->count(2)->create();
        Service::factory()->domain()->for($this->client)->create(['domain' => 'maria.example']);
        Service::factory()->maintenance()->for($this->client)->create();
        Service::factory()->for($this->client)->create(['status' => 'terminated']);
        Service::factory()->for($this->client)->create(['client_visible' => false]);
        Service::factory()->for($this->other)->create();

        Filament::setCurrentPanel('client');
        $kinds = (new Dashboard)->activeByKind();

        $this->assertSame(['Hosting' => 2, 'Domains' => 1, 'Other services' => 1], array_column($kinds, 'count', 'label'));
        $this->assertStringContainsString('kind=hosting', $kinds[0]['url']);
        $this->assertStringContainsString('kind=other', $kinds[2]['url']);

        $this->get('/dashboard')->assertOk()
            ->assertSeeInOrder(['My services', '4 active services', 'Hosting', 'Domains', 'Other services'])
            ->assertSee('gcp-home-tile', false);

        Service::query()->delete();
        $this->assertSame([], (new Dashboard)->activeByKind());
        $this->get('/dashboard')->assertOk()->assertSee('Hosting, domains and more')->assertDontSee('active service');
    }

    public function test_home_lists_the_services_with_the_way_into_the_panel(): void
    {
        $this->fakeWhm();
        $server = Server::factory()->create(['type' => 'cpanel']);
        Service::factory()->domain()->for($this->client)->dueOn('2027-03-01')->create(['domain' => 'maria.example']);
        Service::factory()->maintenance()->for($this->client)->dueOn('2027-01-01')->create(['label' => 'Website care']);
        $hosting = Service::factory()->for($this->client)->dueOn('2027-05-01')->create(['domain' => 'shop.example', 'label' => 'Business hosting', 'server_id' => $server->id, 'username' => 'shop']);
        $suspended = Service::factory()->for($this->client)->dueOn('2027-06-01')->create(['domain' => 'late.example', 'status' => 'suspended', 'server_id' => $server->id, 'username' => 'late']);
        Service::factory()->for($this->client)->create(['domain' => 'gone.example', 'status' => 'terminated']);
        Service::factory()->for($this->other)->create(['domain' => 'theirs.example']);

        Filament::setCurrentPanel('client');
        $rows = collect((new Dashboard)->services());

        // Hosting first (the suspended one before the active), then domains, then the rest
        $this->assertSame([$suspended->id, $hosting->id], $rows->take(2)->pluck('service.id')->all());
        $this->assertSame(['late.example', 'shop.example', 'maria.example'], $rows->take(3)->pluck('service.domain')->all());
        $this->assertSame('Website care', $rows[3]['service']->label);
        $this->assertNull($rows[0]['login']);
        $this->assertSame(route('filament.client.services.panel-login', ['service' => $hosting->id]), $rows[1]['login']);
        $this->assertSame('cPanel', $rows[1]['panel']);

        $this->get('/dashboard')->assertOk()
            ->assertSeeInOrder(['My services', 'late.example', 'Suspended', 'shop.example', 'Business hosting', 'renews 01/05/2027', 'Log in to cPanel', 'maria.example', 'Manage', 'Website care'])
            ->assertDontSee('gone.example')
            ->assertDontSee('theirs.example');

        // The switch under Settings → Client area takes the login away.
        config(['gaiacp.client_area.hosting_login' => false]);
        $this->get('/dashboard')->assertOk()->assertDontSee('Log in to cPanel');

        Service::factory()->for($this->client)->count(3)->create();
        $this->assertCount(Dashboard::SERVICES, (new Dashboard)->services());
    }

    public function test_home_puts_each_notice_and_its_pay_in_the_to_pay_card(): void
    {
        $notice = $this->notice($this->client);
        Service::factory()->domain()->for($this->client)->dueOn(now()->addWeek()->toDateString())->create(['domain' => 'maria.example']);

        $this->get('/dashboard')->assertOk()
            ->assertDontSee('past due')
            ->assertDontSee('Everything is paid')
            ->assertSeeInOrder(['To pay', '1 payment notice', $notice->number, 'Due ', 'Pay', 'My services', 'Coming up for renewal', 'maria.example'])
            ->assertSee(PaymentNoticeResource::getUrl('view', ['record' => $notice], panel: 'client'), false);

        $notice->update(['due_date' => now()->subDays(2)]);

        $this->get('/dashboard')->assertOk()->assertSee('past due')->assertSee('Was due ');

        // More notices than Home lists: the rest are a link away.
        foreach (range(1, Dashboard::NOTICES) as $i) {
            $this->notice($this->client);
        }
        $this->get('/dashboard')->assertOk()->assertSee('All '.(Dashboard::NOTICES + 1).' payment notices to pay');
    }

    public function test_home_says_everything_is_paid_in_one_line(): void
    {
        Service::factory()->domain()->for($this->client)->dueOn('2027-03-01')->create(['domain' => 'maria.example']);

        $this->get('/dashboard')->assertOk()
            ->assertSee('Everything is paid')
            ->assertSee('Next renewal 01/03/2027, maria.example')
            ->assertDontSee('class="gx-big', false);

        Service::query()->delete();
        $this->get('/dashboard')->assertOk()->assertSee('Everything is paid')->assertSee('Nothing renews');
    }

    public function test_renewals_name_the_cycle_and_flag_a_domain_that_will_not_renew(): void
    {
        Service::factory()->for($this->client)->dueOn('2027-02-01')->create(['domain' => 'shop.example', 'label' => 'Business hosting', 'billing_cycle' => 'monthly']);
        Service::factory()->domain()->for($this->client)->dueOn('2027-03-01')->create(['domain' => 'dropping.example', 'auto_terminate' => true]);
        Service::factory()->for($this->client)->dueOn('2027-01-01')->create(['domain' => 'ending.example', 'auto_terminate' => true]);

        Filament::setCurrentPanel('client');
        $this->assertSame(['shop.example', 'dropping.example'], (new Dashboard)->upcoming()->pluck('domain')->all());

        $this->get('/dashboard')->assertOk()
            ->assertSeeInOrder(['Coming up for renewal', 'shop.example', 'Business hosting', 'Monthly', '01/02/2027', 'dropping.example', 'Does not renew automatically']);
    }

    public function test_the_shortcuts_are_what_a_client_comes_to_do(): void
    {
        Filament::setCurrentPanel('client');

        // Nothing to order and no credit here, so those two are left out.
        $this->assertSame(['New ticket', 'Your profile'], array_column((new Dashboard)->shortcuts(), 'label'));

        config(['gaiacp.support.client_tickets' => false]);
        $this->assertSame(['Your profile'], array_column((new Dashboard)->shortcuts(), 'label'));
    }

    public function test_the_search_field_names_what_a_client_finds(): void
    {
        $this->get('/dashboard')->assertOk()
            ->assertSee('Search services, domains, payment notices…')
            ->assertDontSee('Search clients');
    }

    public function test_the_pages_follow_the_clients_language(): void
    {
        $this->client->update(['locale' => 'el']);
        $notice = $this->notice($this->client);

        $this->get("/payment-notices/{$notice->id}")->assertOk()
            ->assertSee('Πρόκειται για ειδοποίηση πληρωμής και όχι για φορολογικό παραστατικό.');
    }

    public function test_the_menu_is_grouped_like_the_admins(): void
    {
        Filament::setCurrentPanel('client');

        $this->get(Dashboard::getUrl(panel: 'client'))->assertOk()
            ->assertSeeInOrder([
                __('Home'), __('Order'),
                __('Services'), __('Hosting'), __('Domains'), __('Other services'),
                __('Billing'), __('Payment notices'), __('Payments'),
                __('Support'), __('Help'),
            ])
            // Initials, not an external avatar service
            ->assertDontSee('ui-avatars.com', false);
    }

    public function test_hosting_and_other_services_list_only_their_kind(): void
    {
        Service::factory()->for($this->client)->create(['label' => 'Business hosting']);
        Service::factory()->maintenance()->for($this->client)->create(['label' => 'Website care']);

        $this->get('/services?kind=hosting')->assertOk()->assertSee('Business hosting')->assertDontSee('Website care');
        $this->get('/services?kind=other')->assertOk()->assertSee('Website care')->assertDontSee('Business hosting');
        $this->get('/services')->assertOk()->assertSee('Business hosting')->assertSee('Website care');
    }

    public function test_the_user_card_sits_in_the_sidebar(): void
    {
        $this->get('/dashboard')->assertOk()->assertSee('<div class="fi-sidebar-footer">', false);

        $plugins = app(PluginManager::class);
        $plugins->disable($plugins->find('gaia_theme'));
        $plugins->disable($plugins->find('flat_theme'));
        $plugins->disable($plugins->find('glass_theme'));

        // No theme: Filament's own place, the top bar
        $this->get('/dashboard')->assertOk()->assertDontSee('<div class="fi-sidebar-footer">', false);
    }

    public function test_client_views_carry_no_styles_of_their_own(): void
    {
        // The theme's client stylesheet (filament.themes.client) holds them, on its colour variables
        foreach (glob(resource_path('views/filament/client/*.blade.php')) as $view) {
            $this->assertStringNotContainsString('<style', file_get_contents($view), basename($view));
        }
    }
}
