<?php

namespace Tests\Feature;

use App\Enums\MailTemplate;
use App\Enums\UserRole;
use App\Filament\Client\Pages\Dashboard;
use App\Filament\Client\Pages\Help;
use App\Filament\Client\Pages\Order;
use App\Filament\Client\Resources\Domains\DomainResource;
use App\Filament\Client\Resources\PaymentNotices\PaymentNoticeResource;
use App\Filament\Client\Resources\Services\ServiceResource;
use App\Invoices\SendInvoiceEmails;
use App\Mail\ClientMail;
use App\Mail\ClientMailer;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** Client area step 7: "View in the client area" in client emails, Home's shortcuts and the Help page. */
class ClientAreaLinksTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $this->client = Client::factory()->create(['email' => 'maria@example.gr']);
        $this->user = User::factory()->create(['role' => UserRole::Client, 'client_id' => $this->client->id, 'email' => $this->client->email]);
    }

    private function notice(): Invoice
    {
        return Invoice::create([
            'number' => Invoice::nextNumber(now()), 'client_id' => $this->client->id, 'date' => now(), 'due_date' => now()->addDays(10),
            'currency' => 'EUR', 'subtotal_cents' => 6000, 'tax_cents' => 0, 'total_cents' => 6000,
        ]);
    }

    private function lastMail(): string
    {
        return Mail::sent(ClientMail::class)->last()->render();
    }

    public function test_a_payment_notice_email_links_to_the_notice(): void
    {
        $notice = $this->notice();

        app(SendInvoiceEmails::class)->send($notice, MailTemplate::InvoiceCreated, evenIfOff: true);

        $html = $this->lastMail();
        $this->assertStringContainsString('View in the client area', $html);
        $this->assertStringContainsString(e(PaymentNoticeResource::getUrl('view', ['record' => $notice], panel: 'client')), $html);
    }

    public function test_service_emails_link_to_the_service_or_domain_and_hidden_ones_to_home(): void
    {
        $hosting = Service::factory()->for($this->client)->create();
        $domain = Service::factory()->domain()->for($this->client)->create();
        $maintenance = Service::factory()->maintenance()->for($this->client)->create(['client_visible' => false]);
        $mailer = app(ClientMailer::class);

        $mailer->send(MailTemplate::ServiceSuspension, $this->client, [], $hosting, evenIfOff: true);
        $this->assertStringContainsString(e(ServiceResource::getUrl('view', ['record' => $hosting], panel: 'client')), $this->lastMail());

        $mailer->send(MailTemplate::DomainRenewal, $this->client, [], $domain, evenIfOff: true);
        $this->assertStringContainsString(e(DomainResource::getUrl('view', ['record' => $domain], panel: 'client')), $this->lastMail());

        $mailer->send(MailTemplate::ServiceSuspension, $this->client, [], $maintenance, evenIfOff: true);
        $html = $this->lastMail();
        $this->assertStringNotContainsString('/services/'.$maintenance->id, $html);
        $this->assertStringContainsString(e(Dashboard::getUrl(panel: 'client')), $html);
    }

    public function test_no_link_without_a_login_on_the_login_emails_or_with_the_switch_off(): void
    {
        $notice = $this->notice();
        $mailer = app(ClientMailer::class);

        $mailer->send(MailTemplate::ClientSignup, $this->client, evenIfOff: true);
        $this->assertStringNotContainsString('View in the client area', $this->lastMail());

        $this->user->update(['active' => false]);
        $mailer->send(MailTemplate::InvoiceCreated, $this->client->refresh(), evenIfOff: true, invoice: $notice);
        $this->assertStringNotContainsString('View in the client area', $this->lastMail());

        $this->user->update(['active' => true]);
        config(['gaiacp.client_area.email_links' => false]);
        $mailer->send(MailTemplate::InvoiceCreated, $this->client->refresh(), evenIfOff: true, invoice: $notice);
        $this->assertStringNotContainsString('View in the client area', $this->lastMail());
    }

    public function test_home_has_shortcuts(): void
    {
        Product::factory()->create();

        $this->actingAs($this->user, 'client')->get('/dashboard')->assertOk()
            ->assertSee(Order::getUrl(panel: 'client'))
            ->assertSee(Help::getUrl(panel: 'client'))
            ->assertSee(PaymentNoticeResource::getUrl(panel: 'client'));
    }

    public function test_the_help_page_describes_only_what_is_switched_on(): void
    {
        Product::factory()->create();

        $this->actingAs($this->user, 'client')->get('/help')->assertOk()
            ->assertSee('Ordering')
            ->assertSee('Change the nameservers')
            ->assertSee('Log in to control panel');

        config(['gaiacp.client_area.orders' => false, 'gaiacp.client_area.domain_nameservers' => false, 'gaiacp.client_area.domain_epp' => false, 'gaiacp.client_area.domain_auto_renew' => false,
            'gaiacp.client_area.domain_contacts' => false, 'gaiacp.client_area.domain_child_nameservers' => false, 'gaiacp.client_area.domain_lock' => false]);
        $this->actingAs($this->user, 'client')->get('/help')->assertOk()
            ->assertDontSee('Ordering')
            ->assertDontSee('On an active domain registered through us')
            ->assertDontSee('if:orders')
            ->assertSee('Log in to control panel');
    }

    public function test_the_help_page_follows_the_language(): void
    {
        $this->user->update(['locale' => 'el']);

        $this->actingAs($this->user, 'client')->get('/help')->assertOk()->assertSee('Ειδοποιήσεις πληρωμής και πληρωμές');
    }
}
