<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Enums\UserRole;
use App\Filament\Client\Pages\Dashboard;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Service;
use App\Models\User;
use App\Plugins\PluginManager;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Client area step 4: the client's own services, domains, payment notices and payments, read-only. */
class ClientAreaPagesTest extends TestCase
{
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
        $this->get("/services/{$hidden->id}")->assertNotFound();
        $this->get("/services/{$others->id}")->assertNotFound();
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
        $this->get("/domains/{$others->id}")->assertNotFound();
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

        $this->get("/payment-notices/{$others->id}")->assertNotFound();
        $this->get("/payment-notices/{$cancelled->id}")->assertNotFound();
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
