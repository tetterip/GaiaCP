<?php

namespace Tests\Feature;

use App\Actions\CompleteOrder;
use App\Actions\Quotes;
use App\Actions\RecordPayment;
use App\Enums\AdminArea;
use App\Enums\BillingCycle;
use App\Enums\LeadStatus;
use App\Enums\MailTemplate;
use App\Enums\QuoteStatus;
use App\Enums\ServiceStatus;
use App\Enums\ServiceType;
use App\Events\GaiaEvent;
use App\Filament\Resources\Leads\Pages\EditLead;
use App\Filament\Resources\Quotes\Pages\CreateQuote;
use App\Filament\Resources\Quotes\Pages\EditQuote;
use App\Filament\Resources\Quotes\Pages\ListQuotes;
use App\Filament\Resources\Quotes\QuoteResource;
use App\Invoices\GenerateInvoices;
use App\Mail\ClientMail;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\Quote;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/** Billing → Quotes (App\Actions\Quotes): written, sent, accepted on their page (an order) or declined, and expired. */
class QuotesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->travelTo(CarbonImmutable::parse('2026-10-25 09:00'));
        Filament::setCurrentPanel('admin');
        $this->admin = User::factory()->create(['name' => 'Admin']);
        $this->actingAs($this->admin);
    }

    private function lead(array $attributes = []): Lead
    {
        return Lead::query()->create($attributes + ['first_name' => 'Maria', 'last_name' => 'Papa', 'email' => 'maria@example.gr', 'country' => 'GR']);
    }

    /** A sent quote for a lead: a site at 120 a year (100 offered instead of the catalogue's) and moving it once for 50. */
    private function quote(array $attributes = []): Quote
    {
        $quote = app(Quotes::class)->create($attributes + [
            'lead_id' => $this->lead()->id,
            'items' => [[
                'type' => ServiceType::Other->value,
                'label' => 'Website care',
                'billing_cycle' => BillingCycle::Annually->value,
                'price_cents' => 10000,
            ]],
            'extras' => [['description' => 'Moving the site', 'amount_cents' => 5000]],
        ]);
        $quote->update(['status' => QuoteStatus::Sent, 'sent_at' => now()]);

        return $quote->refresh();
    }

    public function test_an_admin_writes_a_quote_for_a_lead_from_its_page(): void
    {
        $lead = $this->lead();

        Livewire::test(EditLead::class, ['record' => $lead->id])
            ->assertActionHasUrl('quote', QuoteResource::getUrl('create', ['lead' => $lead->id]));

        $this->get(QuoteResource::getUrl('create', ['lead' => $lead->id]))->assertOk();

        Livewire::withQueryParams(['lead' => $lead->id])
            ->test(CreateQuote::class)
            ->assertSchemaStateSet(['for' => 'lead', 'lead_id' => $lead->id])
            ->fillForm([
                'items' => [[
                    'type' => ServiceType::Other->value,
                    'label' => 'Website care',
                    'billing_cycle' => BillingCycle::Annually->value,
                    'price_cents' => '100,00',
                ]],
                'extras' => [['description' => 'Moving the site', 'amount_cents' => '50']],
                'intro' => 'As we said on the phone',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $quote = Quote::query()->sole();
        $this->assertSame('QT-2026-0001', $quote->number);
        $this->assertSame(QuoteStatus::Draft, $quote->status);
        $this->assertSame($lead->id, $quote->lead_id);
        $this->assertSame('EUR', $quote->currency);
        $this->assertSame('2026-11-24', $quote->valid_until->toDateString()); // 30 days by default
        $this->assertSame(10000, $quote->items[0]['price_cents']);
        $this->assertSame([['description' => 'Moving the site', 'amount_cents' => 5000]], $quote->extras);
        $this->assertSame(15000, $quote->totals()['net']);
        $this->assertSame($this->admin->id, $quote->user_id);

        // Opened again, the prices read as amounts and can be saved unchanged.
        Livewire::test(EditQuote::class, ['record' => $quote->id])
            ->assertSchemaStateSet(fn (array $state) => $this->assertSame([['description' => 'Moving the site', 'amount_cents' => '50.00']], array_values($state['extras'])) ?? ['for' => 'lead'])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame(10000, $quote->refresh()->items[0]['price_cents']);
    }

    public function test_sending_emails_the_link_and_the_lead_is_quoted(): void
    {
        $quote = $this->quote();
        $quote->update(['status' => QuoteStatus::Draft, 'sent_at' => null]);

        // A draft's link shows nothing.
        $this->get($quote->url())->assertNotFound();

        Livewire::test(EditQuote::class, ['record' => $quote->id])
            ->callAction('send')
            ->assertNotified();

        $quote->refresh();
        $this->assertSame(QuoteStatus::Sent, $quote->status);
        $this->assertSame(LeadStatus::Quoted, $quote->lead->status);
        Mail::assertSent(ClientMail::class, fn (ClientMail $mail) => $mail->template() === MailTemplate::Quote && $mail->hasTo('maria@example.gr'));
        $this->assertTrue(ActivityLog::query()->where('event', 'quote.sent')->exists());

        auth()->logout();
        $this->get($quote->url())->assertOk()->assertSee('QT-2026-0001')->assertSee('Moving the site')->assertSee('Accept');
    }

    public function test_a_quote_without_an_email_or_past_its_date_is_not_sent(): void
    {
        $quote = $this->quote(['lead_id' => $this->lead(['email' => null])->id]);

        $this->assertThrows(fn () => app(Quotes::class)->send($quote), RuntimeException::class);

        $quote = $this->quote(['valid_until' => today()->subDay()]);
        $this->assertThrows(fn () => app(Quotes::class)->send($quote), RuntimeException::class);
        Mail::assertNothingSent();
    }

    public function test_accepting_on_its_page_makes_the_lead_a_client_and_places_the_order(): void
    {
        $sales = User::factory()->limitedTo([AdminArea::Sales])->create();
        $quote = $this->quote();
        auth()->logout();

        $this->post(route('quote.accept', ['token' => $quote->token]))->assertRedirect($quote->url());

        $quote->refresh();
        $this->assertSame(QuoteStatus::Accepted, $quote->status);
        $this->assertNotNull($quote->order_id);
        $this->assertSame('127.0.0.1', $quote->accepted_ip);

        $client = Client::query()->sole();
        $this->assertSame('maria@example.gr', $client->email);
        $this->assertSame($client->id, $quote->client_id);
        $this->assertSame(LeadStatus::Won, $quote->lead->status);
        Mail::assertSent(ClientMail::class, fn (ClientMail $mail) => $mail->template() === MailTemplate::ClientAreaInvite);

        // The order at the quote's price, its one-off line an other service billed once, both on the first notice.
        [$service, $once] = Service::query()->orderBy('id')->get()->all();
        $this->assertSame($quote->order_id, $service->order_id);
        $this->assertSame(ServiceStatus::Pending, $service->status);
        $this->assertSame(10000, $service->price_cents);
        $this->assertSame(BillingCycle::Annually, $service->billing_cycle);
        $this->assertSame($quote->order_id, $once->order_id);
        $this->assertSame(ServiceType::Other, $once->type);
        $this->assertSame('Moving the site', $once->label);
        $this->assertSame(BillingCycle::OneTime, $once->billing_cycle);
        $this->assertSame(5000, $once->price_cents);

        $invoice = Invoice::query()->sole();
        $this->assertSame(15000, $invoice->items->sum('amount_cents'));

        $this->assertSame(['Quote QT-2026-0001 accepted'], $sales->notifications()->get()->pluck('data.title')->all());
        $this->get($quote->url())->assertOk()->assertDontSee('name="accept_terms"', false);

        // A second click finds it accepted: no second order.
        $this->post(route('quote.accept', ['token' => $quote->token]))->assertSessionHasErrors('quote');
        $this->assertSame(2, Service::query()->count());
    }

    public function test_the_one_off_line_is_billed_once(): void
    {
        $client = Client::factory()->create();
        $quote = $this->quote(['client_id' => $client->id, 'lead_id' => null]);
        $quote = app(Quotes::class)->accept($quote);
        [$service, $once] = Service::query()->orderBy('id')->get()->all();
        $invoice = Invoice::query()->sole();

        app(RecordPayment::class)->handle($service, 10000, today(), 'bank_transfer');
        app(RecordPayment::class)->handle($once, 5000, today(), 'bank_transfer');
        $this->assertSame('2027-10-25', $service->refresh()->next_due_date->toDateString());
        $this->assertSame('2026-10-25', $once->refresh()->next_due_date->toDateString());
        $this->assertNotNull($invoice->refresh()->paid_on);

        // Set up after the payment (Complete order), the one-off service is done.
        app(CompleteOrder::class)->handle($quote->order);
        $this->assertSame(ServiceStatus::Active, $service->refresh()->status);
        $this->assertSame(ServiceStatus::Completed, $once->refresh()->status);

        $this->travelTo(CarbonImmutable::parse('2027-10-15 09:00'));
        app(GenerateInvoices::class)->handle(today());

        $renewal = Invoice::query()->latest('id')->first();
        $this->assertNotSame($invoice->id, $renewal->id);
        $this->assertSame([10000], $renewal->items->pluck('amount_cents')->all());
    }

    public function test_a_quote_of_only_one_off_lines_orders_them(): void
    {
        $quote = $this->quote(['items' => []]);

        app(Quotes::class)->accept($quote);

        $service = Service::query()->sole();
        $this->assertSame(BillingCycle::OneTime, $service->billing_cycle);
        $this->assertSame(5000, Invoice::query()->sole()->items->sum('amount_cents'));
    }

    public function test_the_terms_are_asked_when_the_client_area_asks_for_them(): void
    {
        config(['gaiacp.client_area.terms' => true, 'gaiacp.client_area.terms_text' => 'Be nice.', 'gaiacp.client_area.terms_version' => '2.0']);
        $quote = $this->quote();
        auth()->logout();

        $this->get($quote->url())->assertSee('name="accept_terms"', false);
        $this->post(route('quote.accept', ['token' => $quote->token]))->assertSessionHasErrors('quote');
        $this->assertSame(QuoteStatus::Sent, $quote->refresh()->status);

        $this->post(route('quote.accept', ['token' => $quote->token]), ['accept_terms' => '1'])->assertRedirect();
        $this->assertSame('2.0', $quote->refresh()->terms_version);
        $this->assertSame('2.0', $quote->order->terms_version);
    }

    public function test_a_domain_already_with_us_stops_the_acceptance(): void
    {
        $client = Client::factory()->create();
        Service::factory()->for($client)->create(['type' => ServiceType::Domain, 'domain' => 'maria.gr', 'status' => ServiceStatus::Active]);
        $quote = $this->quote(['items' => [[
            'type' => ServiceType::Domain->value,
            'domain' => 'maria.gr',
            'registration_type' => 'register',
            'billing_cycle' => BillingCycle::Annually->value,
            'price_cents' => 1500,
        ]]]);

        $this->assertThrows(fn () => app(Quotes::class)->accept($quote), RuntimeException::class, 'maria.gr is already with us');
        $this->assertSame(QuoteStatus::Sent, $quote->refresh()->status);
        $this->assertSame(1, Client::query()->count());
    }

    public function test_a_domain_line_keeps_its_greek_label_whole(): void
    {
        app()->setLocale('el');
        $quote = $this->quote(['items' => [['type' => ServiceType::Domain->value, 'domain' => 'maria.gr', 'registration_type' => 'register', 'billing_cycle' => BillingCycle::Annually->value, 'price_cents' => 1500]]]);

        $this->assertSame('maria.gr · Καταχώριση', $quote->lines()[0]['description']);
    }

    public function test_declining_keeps_the_reason_and_rings_the_bell(): void
    {
        $sales = User::factory()->limitedTo([AdminArea::Sales])->create();
        $quote = $this->quote();
        auth()->logout();

        $this->post(route('quote.decline', ['token' => $quote->token]), ['reason' => 'Too expensive'])->assertRedirect($quote->url());

        $quote->refresh();
        $this->assertSame(QuoteStatus::Declined, $quote->status);
        $this->assertSame('Too expensive', $quote->decline_reason);
        $this->assertSame(['Quote QT-2026-0001 declined'], $sales->notifications()->get()->pluck('data.title')->all());
        $this->get($quote->url())->assertOk()->assertDontSee('name="reason"', false);
        $this->assertSame(0, Client::query()->count());
    }

    public function test_an_admin_accepts_for_the_client_and_the_quote_is_read_only_then(): void
    {
        $quote = $this->quote();

        Livewire::test(EditQuote::class, ['record' => $quote->id])
            ->callAction(TestAction::make('accept'))
            ->assertNotified();

        $quote->refresh();
        $this->assertSame(QuoteStatus::Accepted, $quote->status);
        $this->assertNull($quote->accepted_ip);
        $this->assertSame(2, Service::query()->count());

        Livewire::test(EditQuote::class, ['record' => $quote->id])
            ->assertSchemaComponentStateSet('valid_until', $quote->valid_until->toDateString())
            ->assertActionHidden('send')
            ->assertActionVisible('order')
            ->assertActionHidden(TestAction::make('accept'));
    }

    public function test_duplicate_starts_a_new_draft(): void
    {
        $quote = $this->quote();
        $quote->update(['status' => QuoteStatus::Declined]);

        Livewire::test(EditQuote::class, ['record' => $quote->id])->callAction(TestAction::make('duplicate'));

        $copy = Quote::query()->latest('id')->first();
        $this->assertSame('QT-2026-0002', $copy->number);
        $this->assertSame(QuoteStatus::Draft, $copy->status);
        $this->assertSame($quote->items, $copy->items);
        $this->assertSame($quote->extras, $copy->extras);
        $this->assertNotSame($quote->token, $copy->token);
    }

    public function test_sent_quotes_past_their_date_expire(): void
    {
        $old = $this->quote(['valid_until' => today()->subDay()]);
        $fresh = $this->quote();

        $this->artisan('gaiacp:expire-quotes --dry-run')->expectsOutputToContain('1 to expire')->assertSuccessful();
        $this->assertSame(QuoteStatus::Sent, $old->refresh()->status);

        $this->artisan('gaiacp:expire-quotes')->expectsOutputToContain('1 expired')->assertSuccessful();
        $this->assertSame(QuoteStatus::Expired, $old->refresh()->status);
        $this->assertSame(QuoteStatus::Sent, $fresh->refresh()->status);
        $this->artisan('gaiacp:expire-quotes')->expectsOutputToContain('0 expired');

        // Its link says so and takes no answer.
        $this->post(route('quote.accept', ['token' => $old->token]))->assertSessionHasErrors('quote');
    }

    public function test_the_list_shows_open_quotes_and_only_sales_sees_them(): void
    {
        $open = $this->quote();
        $declined = $this->quote();
        $declined->update(['status' => QuoteStatus::Declined]);

        Livewire::test(ListQuotes::class)
            ->assertCanSeeTableRecords([$open])
            ->assertCanNotSeeTableRecords([$declined]);

        $this->actingAs(User::factory()->limitedTo([AdminArea::Billing])->create());
        $this->get(QuoteResource::getUrl())->assertForbidden();

        $this->actingAs(User::factory()->limitedTo([AdminArea::Sales])->create());
        $this->get(QuoteResource::getUrl())->assertOk();
    }

    public function test_quote_events_reach_plugins_with_the_quote(): void
    {
        Event::fake([GaiaEvent::class]);
        $quote = $this->quote();
        auth()->logout();

        $this->post(route('quote.decline', ['token' => $quote->token]));

        Event::assertDispatched(GaiaEvent::class, fn (GaiaEvent $event) => $event->name === 'quote.declined'
            && $event->payload()['quote']['number'] === 'QT-2026-0001'
            && $event->payload()['quote']['total_cents'] === 15000);
    }
}
