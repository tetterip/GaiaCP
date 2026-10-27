<?php

namespace Tests\Feature;

use App\Enums\AdminArea;
use App\Enums\BillingCycle;
use App\Enums\InvoiceStatus;
use App\Enums\UserRole;
use App\Filament\Client\Resources\PaymentNotices\Pages\ViewPaymentNotice;
use App\Mail\DailySummaryMail;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use App\Plugins\PluginManager;
use App\Reports\DailySummary;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Plugins\Stripe\StripeApi;
use Plugins\Stripe\StripeCheckout;
use Plugins\Stripe\StripeCheckouts;
use Plugins\Stripe\StripeSettings;
use Plugins\Stripe\WebhookSignature;
use Tests\TestCase;

/** The Stripe plugin: "Pay by card" on the client's payment notices, recorded once Stripe confirms the payment. */
class StripePaymentsTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'whsec_test_secret';

    private Client $client;

    private Service $service;

    private Invoice $notice;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config(['gaiacp.stripe' => ['mode' => 'test', 'test_secret_key' => 'sk_test_123', 'test_webhook_secret' => self::SECRET, 'live_secret_key' => null, 'live_webhook_secret' => null]]);

        $this->client = Client::factory()->create(['email' => 'maria@example.gr']);
        $due = CarbonImmutable::today()->addDays(10);
        $this->service = Service::factory()->for($this->client)->create([
            'label' => 'Web hosting maria.gr', 'start_date' => $due->subYear(), 'next_due_date' => $due,
            'billing_cycle' => BillingCycle::Annually, 'price_cents' => 6000,
        ]);
        $this->notice = Invoice::create([
            'number' => Invoice::nextNumber(now()), 'client_id' => $this->client->id, 'date' => today(), 'due_date' => $due,
            'currency' => 'EUR', 'subtotal_cents' => 6000, 'tax_cents' => 0, 'total_cents' => 6000,
        ]);
        $this->notice->items()->create([
            'service_id' => $this->service->id, 'description' => 'Web hosting maria.gr', 'due_date' => $due, 'period_end' => $due->addYear(),
            'amount_cents' => 6000, 'tax_cents' => 0, 'total_cents' => 6000,
        ]);
    }

    private function actAsClient(): void
    {
        $user = User::factory()->create(['role' => UserRole::Client, 'client_id' => $this->client->id, 'email' => $this->client->email]);
        $this->actingAs($user, 'client');
        Filament::setCurrentPanel('client');
    }

    private function fakeStripe(array $session = []): void
    {
        Http::fake([
            StripeApi::BASE_URL.'/checkout/sessions/*' => Http::response($session + ['id' => 'cs_test_1', 'payment_status' => 'paid', 'amount_total' => 6000, 'currency' => 'eur', 'payment_intent' => 'pi_1']),
            StripeApi::BASE_URL.'/checkout/sessions' => Http::response(['id' => 'cs_test_1', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_1', 'livemode' => false]),
        ]);
    }

    /** "Pay by card" pressed: a Checkout Session for cs_test_1 ($session: what Stripe says about it later). */
    private function startCheckout(array $session = []): void
    {
        $this->actAsClient();
        $this->fakeStripe($session);

        Livewire::test(ViewPaymentNotice::class, ['record' => $this->notice->getRouteKey()])
            ->callAction('payByCard')
            ->assertRedirect('https://checkout.stripe.com/c/pay/cs_test_1');
    }

    /** Stripe posting an event, signed with $secret. */
    private function webhook(string $type, array $session = [], string $secret = self::SECRET, ?int $time = null): TestResponse
    {
        $payload = json_encode(['id' => 'evt_1', 'type' => $type, 'data' => ['object' => $session + [
            'id' => 'cs_test_1', 'object' => 'checkout.session', 'payment_status' => 'paid', 'amount_total' => 6000, 'currency' => 'eur', 'payment_intent' => 'pi_1',
        ]]]);
        $time ??= time();
        $signature = 't='.$time.',v1='.hash_hmac('sha256', $time.'.'.$payload, $secret);

        return $this->call('POST', '/webhooks/stripe', [], [], [], ['HTTP_STRIPE_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'], $payload);
    }

    public function test_unpaid_notices_get_pay_by_card_once_stripe_is_set_up(): void
    {
        $this->actAsClient();
        $page = fn () => Livewire::test(ViewPaymentNotice::class, ['record' => $this->notice->getRouteKey()]);

        $page()->assertActionVisible('payByCard');
        $this->get("/payment-notices/{$this->notice->id}")->assertSee('Pay by card');

        config(['gaiacp.stripe.test_secret_key' => null]);
        $page()->assertActionDoesNotExist('payByCard');

        // The live mode has its own key.
        config(['gaiacp.stripe.mode' => 'live', 'gaiacp.stripe.live_secret_key' => 'sk_live_1']);
        $page()->assertActionVisible('payByCard');

        $plugins = app(PluginManager::class);
        $plugins->disable($plugins->find('stripe'));
        $page()->assertActionDoesNotExist('payByCard');
        $plugins->enable($plugins->find('stripe'));

        $this->notice->update(['status' => InvoiceStatus::Paid, 'paid_on' => today()]);
        $page()->assertActionHidden('payByCard');
    }

    public function test_a_line_whose_service_moved_on_hides_the_button(): void
    {
        $this->actAsClient();
        $this->service->update(['next_due_date' => $this->service->next_due_date->addYear()]);

        Livewire::test(ViewPaymentNotice::class, ['record' => $this->notice->getRouteKey()])->assertActionHidden('payByCard');
    }

    public function test_pay_by_card_opens_stripe_checkout_for_what_the_notice_owes(): void
    {
        $this->startCheckout();

        Http::assertSent(function (Request $request) {
            return $request->url() === StripeApi::BASE_URL.'/checkout/sessions'
                && $request->hasHeader('Authorization', 'Bearer sk_test_123')
                && $request['mode'] === 'payment'
                && $request['line_items'][0]['price_data']['unit_amount'] == 6000
                && $request['line_items'][0]['price_data']['currency'] === 'eur'
                && str_contains($request['line_items'][0]['price_data']['product_data']['name'], $this->notice->number)
                && $request['client_reference_id'] == $this->notice->id
                && $request['customer_email'] === 'maria@example.gr'
                && $request['success_url'] === url('/stripe/return').'?session_id={CHECKOUT_SESSION_ID}'
                && $request['cancel_url'] === url("/payment-notices/{$this->notice->id}");
        });

        $checkout = StripeCheckout::sole();
        $this->assertSame('cs_test_1', $checkout->session_id);
        $this->assertSame(6000, $checkout->amount_cents);
        $this->assertSame(StripeCheckout::OPEN, $checkout->status);
        $this->assertSame(0, Payment::count());
    }

    public function test_stripe_refusing_shows_a_message_and_stays_on_the_notice(): void
    {
        $this->actAsClient();
        Http::fake([StripeApi::BASE_URL.'/*' => Http::response(['error' => ['message' => 'Invalid API Key provided']], 401)]);

        Livewire::test(ViewPaymentNotice::class, ['record' => $this->notice->getRouteKey()])
            ->callAction('payByCard')
            ->assertNoRedirect()
            ->assertNotified(__('Paying by card is not available right now. Please try again later.'));

        $this->assertSame(0, StripeCheckout::count());
    }

    public function test_the_signed_webhook_records_the_payment_once(): void
    {
        $this->startCheckout();
        $before = $this->service->next_due_date;

        $this->webhook('checkout.session.completed')->assertOk();
        $this->webhook('checkout.session.completed')->assertOk(); // Stripe retries

        $payment = Payment::sole();
        $this->assertSame('stripe', $payment->method);
        $this->assertSame('pi_1', $payment->reference);
        $this->assertSame(6000, $payment->amount_cents);
        $this->assertSame(InvoiceStatus::Paid, $this->notice->fresh()->status);
        $this->assertTrue($this->service->fresh()->next_due_date->equalTo($before->addYear()));
        $this->assertSame(StripeCheckout::PAID, StripeCheckout::sole()->status);
        $this->assertDatabaseHas('activity_logs', ['event' => 'stripe.paid']);
    }

    public function test_an_unsigned_or_old_webhook_is_refused(): void
    {
        $this->startCheckout();

        $this->webhook('checkout.session.completed', secret: 'whsec_someone_else')->assertStatus(400);
        $this->webhook('checkout.session.completed', time: time() - WebhookSignature::TOLERANCE - 60)->assertStatus(400);
        $this->postJson('/webhooks/stripe', ['type' => 'checkout.session.completed'])->assertStatus(400);

        $this->assertSame(0, Payment::count());
        $this->assertSame(InvoiceStatus::Unpaid, $this->notice->fresh()->status);
    }

    public function test_the_webhook_needs_no_session_or_csrf_token_and_ignores_other_sessions(): void
    {
        $this->webhook('checkout.session.completed', ['id' => 'cs_not_ours'])->assertOk()->assertJson(['received' => true]);
        $this->webhook('payment_intent.created')->assertOk();

        $this->assertSame(0, Payment::count());
    }

    public function test_a_payment_still_clearing_waits_for_its_success_event(): void
    {
        $this->startCheckout();

        $this->webhook('checkout.session.completed', ['payment_status' => 'unpaid'])->assertOk();
        $this->assertSame(0, Payment::count());
        $this->assertSame(StripeCheckout::OPEN, StripeCheckout::sole()->status);

        $this->webhook('checkout.session.async_payment_succeeded')->assertOk();
        $this->assertSame(1, Payment::count());
    }

    public function test_expired_and_failed_pages_are_closed_without_a_payment(): void
    {
        $this->startCheckout();

        $this->webhook('checkout.session.expired', ['payment_status' => 'unpaid'])->assertOk();

        $this->assertSame(StripeCheckout::EXPIRED, StripeCheckout::sole()->status);
        $this->assertSame(0, Payment::count());
    }

    public function test_coming_back_from_stripe_records_what_stripe_says_about_the_session(): void
    {
        $this->startCheckout();

        $this->get('/stripe/return?session_id=cs_test_1')->assertRedirect("/payment-notices/{$this->notice->id}");

        Http::assertSent(fn (Request $request) => $request->url() === StripeApi::BASE_URL.'/checkout/sessions/cs_test_1');
        $this->assertSame(1, Payment::count());
        $this->assertSame(InvoiceStatus::Paid, $this->notice->fresh()->status);

        // The webhook arriving afterwards changes nothing.
        $this->webhook('checkout.session.completed')->assertOk();
        $this->assertSame(1, Payment::count());
    }

    public function test_coming_back_unpaid_records_nothing(): void
    {
        $this->startCheckout(['payment_status' => 'unpaid', 'payment_intent' => null]);

        $this->get('/stripe/return?session_id=cs_test_1')->assertRedirect("/payment-notices/{$this->notice->id}");
        $this->get('/stripe/return?session_id=cs_made_up')->assertRedirect(Filament::getPanel('client')->getUrl());

        $this->assertSame(0, Payment::count());
    }

    public function test_a_notice_paid_meanwhile_is_left_for_an_admin_and_in_the_daily_summary(): void
    {
        $this->startCheckout();
        $since = CarbonImmutable::now()->subMinute();
        $this->notice->update(['status' => InvoiceStatus::Paid, 'paid_on' => today()]); // paid by bank transfer meanwhile

        $this->webhook('checkout.session.completed')->assertOk();

        $this->assertSame(0, Payment::count());
        $checkout = StripeCheckout::sole();
        $this->assertSame(StripeCheckout::UNMATCHED, $checkout->status);
        $this->assertSame('The payment notice is no longer unpaid', $checkout->error);

        $log = ActivityLog::query()->where('event', 'payment.online_unmatched')->sole();
        $this->assertSame($this->client->id, $log->client_id);

        $summary = DailySummary::between($since, CarbonImmutable::now()->addMinute());
        $this->assertFalse($summary->isEmpty());
        $this->assertCount(1, $summary->unmatchedPayments);
        Filament::setCurrentPanel('admin');
        $this->assertStringContainsString($this->notice->number.' was paid online but not recorded', (new DailySummaryMail($summary))->locale('en')->render());
    }

    public function test_a_payment_whose_recording_was_cut_off_is_handed_to_an_admin(): void
    {
        $this->startCheckout();
        StripeCheckout::sole()->update(['status' => StripeCheckout::PROCESSING]); // the request died part way

        $this->assertSame(0, app(StripeCheckouts::class)->flagStuck()); // still recording, maybe

        $this->travel(61)->minutes();
        $this->assertSame(1, app(StripeCheckouts::class)->flagStuck());
        $this->assertSame(StripeCheckout::UNMATCHED, StripeCheckout::sole()->status);
        $this->assertDatabaseHas('activity_logs', ['event' => 'payment.online_unmatched', 'client_id' => $this->client->id]);
    }

    public function test_a_different_amount_charged_is_not_recorded(): void
    {
        $this->startCheckout();

        $this->webhook('checkout.session.completed', ['amount_total' => 100])->assertOk();

        $this->assertSame(0, Payment::count());
        $this->assertSame(StripeCheckout::UNMATCHED, StripeCheckout::sole()->status);
    }

    public function test_while_the_plugin_is_off_its_routes_are_404(): void
    {
        $this->startCheckout();
        $plugins = app(PluginManager::class);
        $plugins->disable($plugins->find('stripe'));

        $this->webhook('checkout.session.completed')->assertNotFound();
        $this->get('/stripe/return?session_id=cs_test_1')->assertNotFound();
        $this->assertSame(0, Payment::count());
    }

    public function test_the_help_page_and_payment_instructions_mention_it_only_while_it_is_set_up(): void
    {
        $this->actAsClient();
        $this->get('/help')->assertOk()->assertSee('Pay by card');
        $this->get("/payment-notices/{$this->notice->id}")->assertSee(__('Pay by card in our client area: open the payment notice and press Pay by card.'));

        config(['gaiacp.stripe.test_secret_key' => null]);
        $this->get('/help')->assertOk()->assertDontSee('Pay by card');
    }

    public function test_the_settings_page_stores_the_keys_encrypted_and_shows_the_webhook(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(StripeSettings::class)
            ->assertSet('data.stripe.test_secret_key', null)
            ->assertSchemaStateSet(['webhook_url' => url('/webhooks/stripe')])
            ->assertSee('checkout.session.completed')
            ->fillForm(['stripe' => ['mode' => 'live', 'live_secret_key' => 'sk_live_new', 'live_webhook_secret' => 'whsec_live']])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertNotSame('sk_live_new', Setting::query()->find('stripe.live_secret_key')->value);
        $this->assertSame('sk_live_new', config('gaiacp.stripe.live_secret_key'));
        $this->assertSame('live', config('gaiacp.stripe.mode'));
        $this->assertSame('sk_test_123', config('gaiacp.stripe.test_secret_key')); // blank kept it

        // A test key in the live field is refused.
        Livewire::test(StripeSettings::class)
            ->fillForm(['stripe' => ['mode' => 'live', 'live_secret_key' => 'sk_test_oops']])
            ->call('save')
            ->assertHasFormErrors(['stripe.live_secret_key']);
    }

    public function test_check_the_key_calls_stripe(): void
    {
        $this->actingAs(User::factory()->create());
        Http::fake([StripeApi::BASE_URL.'/*' => Http::response(['data' => []])]);

        Livewire::test(StripeSettings::class)->callAction('checkKey')->assertNotified(__('The test key works'));
    }

    public function test_admins_without_full_access_cant_open_the_settings(): void
    {
        User::factory()->create(); // the first admin is a super admin
        $this->actingAs(User::factory()->limitedTo([AdminArea::BusinessSettings])->create());

        $this->get(StripeSettings::getUrl(panel: 'admin'))->assertForbidden();
    }
}
