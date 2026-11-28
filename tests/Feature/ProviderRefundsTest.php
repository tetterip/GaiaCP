<?php

namespace Tests\Feature;

use App\Actions\RecordInvoicePayment;
use App\Actions\RefundPayment;
use App\Enums\BillingCycle;
use App\Filament\Resources\Services\Pages\EditService;
use App\Filament\Resources\Services\RelationManagers\PaymentsRelationManager;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\ProviderPaymentState;
use App\Models\Service;
use App\Models\User;
use App\Plugins\PluginManager;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Plugins\PayPal\PayPalApi;
use Plugins\PayPal\PayPalOrder;
use Plugins\Stripe\StripeApi;
use Plugins\Stripe\StripeCheckout;
use RuntimeException;
use Tests\TestCase;

/**
 * Refunds and chargebacks reported by Stripe and PayPal (bell and log, nothing changed), and Refund payment giving a
 * payment back at the gateway.
 */
class ProviderRefundsTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'whsec_test_secret';

    private const PAYPAL = PayPalApi::SANDBOX_URL;

    private User $admin;

    /** What PayPal's API says about dispute PP-D-1. */
    private array $dispute = [];

    private Client $client;

    private Service $service;

    private Invoice $notice;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config(['gaiacp.stripe' => ['mode' => 'test', 'test_secret_key' => 'sk_test_123', 'test_webhook_secret' => self::SECRET, 'live_secret_key' => null, 'live_webhook_secret' => null]]);
        config(['gaiacp.paypal' => ['mode' => 'sandbox', 'sandbox_client_id' => 'AcLientId', 'sandbox_secret' => 'EsEcret', 'live_client_id' => null, 'live_secret' => null]]);
        $plugins = app(PluginManager::class);
        $plugins->enable($plugins->find('paypal'));

        $this->admin = User::factory()->create();
        $this->client = Client::factory()->create(['first_name' => 'Maria', 'last_name' => 'Papadopoulou']);
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

    private function paidWithStripe(bool $live = false): Payment
    {
        StripeCheckout::create(['invoice_id' => $this->notice->id, 'session_id' => 'cs_1', 'livemode' => $live, 'amount_cents' => 6000, 'currency' => 'EUR', 'status' => StripeCheckout::PAID, 'payment_intent' => 'pi_1']);
        app(RecordInvoicePayment::class)->handle($this->notice, CarbonImmutable::now(), 'stripe', 'pi_1');
        $this->admin->notifications()->delete(); // "Payment received"

        return Payment::query()->where('reference', 'pi_1')->sole();
    }

    private function paidWithPaypal(): Payment
    {
        PayPalOrder::create(['invoice_id' => $this->notice->id, 'order_id' => 'ORDER1', 'live' => false, 'amount_cents' => 6000, 'currency' => 'EUR', 'status' => PayPalOrder::PAID, 'capture_id' => 'CAP1']);
        app(RecordInvoicePayment::class)->handle($this->notice, CarbonImmutable::now(), 'paypal', 'CAP1');
        $this->admin->notifications()->delete();

        return Payment::query()->where('reference', 'CAP1')->sole();
    }

    /** Stripe posting a signed event about a Charge or a Dispute. */
    private function stripe(string $type, array $object, bool $live = false): TestResponse
    {
        $payload = json_encode(['id' => 'evt_'.uniqid(), 'livemode' => $live, 'type' => $type, 'data' => ['object' => $object]]);
        $time = time();
        $signature = 't='.$time.',v1='.hash_hmac('sha256', $time.'.'.$payload, self::SECRET);

        return $this->call('POST', '/webhooks/stripe', [], [], [], ['HTTP_STRIPE_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'], $payload);
    }

    private function bell(): array
    {
        return $this->admin->notifications()->get()->map(fn ($n) => $n->data['title'])->all();
    }

    public function test_a_refund_made_in_stripes_dashboard_rings_the_bell_once_and_changes_nothing(): void
    {
        $payment = $this->paidWithStripe();
        $charge = ['id' => 'ch_1', 'object' => 'charge', 'payment_intent' => 'pi_1', 'amount' => 6000, 'amount_refunded' => 2000, 'currency' => 'eur'];

        $this->stripe('charge.refunded', $charge)->assertOk();
        $this->stripe('charge.refunded', $charge)->assertOk(); // Stripe sends it again

        $this->assertSame(1, ActivityLog::query()->where('event', 'payment.provider_refunded')->count());
        $this->assertSame(["Refunded at Card (Stripe): {$this->notice->number}"], $this->bell());
        $this->assertSame(2000, ProviderPaymentState::for($payment)->refunded_cents);
        $this->assertSame('Refunded at the provider: €20.00', ProviderPaymentState::note($payment));
        // Nothing recorded on its own
        $this->assertSame(1, Payment::query()->count());

        // The rest given back later: one more bell, for the new total
        $this->stripe('charge.refunded', ['amount_refunded' => 6000] + $charge)->assertOk();
        $this->assertSame(2, ActivityLog::query()->where('event', 'payment.provider_refunded')->count());
        $this->assertSame(6000, ProviderPaymentState::for($payment)->refunded_cents);
    }

    public function test_stripe_reports_about_payments_gaiacp_did_not_take_are_ignored(): void
    {
        $this->paidWithStripe();

        // Another sale on the same Stripe account, and a test event about a live payment
        $this->stripe('charge.refunded', ['payment_intent' => 'pi_other', 'amount_refunded' => 6000, 'currency' => 'eur'])->assertOk();
        $this->stripe('charge.refunded', ['payment_intent' => 'pi_1', 'amount_refunded' => 6000, 'currency' => 'eur'], live: true)->assertStatus(400); // no live secret

        $this->assertSame(0, ActivityLog::query()->where('event', 'like', 'payment.%refunded')->count());
        $this->assertSame([], $this->bell());
    }

    public function test_a_stripe_chargeback_rings_the_bell_when_opened_and_when_closed(): void
    {
        $payment = $this->paidWithStripe();
        $dispute = ['id' => 'dp_1', 'object' => 'dispute', 'charge' => 'ch_1', 'payment_intent' => 'pi_1', 'amount' => 6000, 'currency' => 'eur', 'reason' => 'fraudulent', 'status' => 'needs_response'];

        $this->stripe('charge.dispute.created', $dispute)->assertOk();
        $this->stripe('charge.dispute.created', $dispute)->assertOk();

        $log = ActivityLog::query()->where('event', 'payment.disputed')->sole();
        $this->assertStringContainsString('(fraudulent)', $log->description);
        $this->assertSame('Chargeback open', ProviderPaymentState::note($payment));
        $this->assertSame(["Chargeback: {$this->notice->number}"], $this->bell());

        $this->stripe('charge.dispute.closed', ['status' => 'lost'] + $dispute)->assertOk();
        $this->stripe('charge.dispute.closed', ['status' => 'lost'] + $dispute)->assertOk();

        $this->assertSame(1, ActivityLog::query()->where('event', 'payment.dispute_closed')->count());
        $this->assertSame('Chargeback lost', ProviderPaymentState::note($payment));
        $this->assertContains("Chargeback lost: {$this->notice->number}", $this->bell());

        // Nothing changed here: the payment, the notice and the service stay
        $this->assertSame(1, Payment::query()->count());
        $this->assertTrue($this->service->fresh()->next_due_date->greaterThan(today()->addMonths(6)));
    }

    public function test_refund_payment_gives_a_stripe_payment_back_at_stripe_and_its_report_rings_no_bell(): void
    {
        $payment = $this->paidWithStripe();
        Http::fake([StripeApi::BASE_URL.'/refunds' => Http::response(['id' => 're_1', 'status' => 'succeeded'])]);

        $this->assertNotNull(RefundPayment::provider($payment));
        $refund = app(RefundPayment::class)->handle($payment, RefundPayment::AT_PROVIDER);

        Http::assertSent(fn (Request $request) => $request->url() === StripeApi::BASE_URL.'/refunds'
            && $request['payment_intent'] === 'pi_1' && (int) $request['amount'] === 6000
            && $request->header('Idempotency-Key')[0] === 'gaiacp-refund-payment-'.$payment->id);
        $this->assertSame(-6000, $refund->amount_cents);
        $this->assertStringContainsString('re_1', $refund->notes);

        // Stripe's webhook about that refund: already recorded, so no bell
        $this->stripe('charge.refunded', ['payment_intent' => 'pi_1', 'amount_refunded' => 6000, 'currency' => 'eur'])->assertOk();
        $this->assertSame(0, ActivityLog::query()->where('event', 'payment.provider_refunded')->count());
        $this->assertSame([], $this->bell());
    }

    public function test_a_refund_stripe_refuses_records_nothing(): void
    {
        $payment = $this->paidWithStripe();
        Http::fake([StripeApi::BASE_URL.'/refunds' => Http::response(['error' => ['message' => 'Charge has been disputed']], 400)]);

        try {
            app(RefundPayment::class)->handle($payment, RefundPayment::AT_PROVIDER);
            $this->fail('The refund should have been refused.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Charge has been disputed', $e->getMessage());
        }

        $this->assertSame(1, Payment::query()->count());
        $this->assertTrue(RefundPayment::canRefund($payment));
    }

    public function test_only_payments_of_the_mode_in_use_are_offered_a_refund_at_stripe(): void
    {
        $payment = $this->paidWithStripe(live: true);
        $this->assertNull(RefundPayment::provider($payment));

        config(['gaiacp.stripe.mode' => 'live', 'gaiacp.stripe.live_secret_key' => 'sk_live_1']);
        $this->assertNotNull(RefundPayment::provider($payment));

        // A payment recorded by hand has no gateway to refund it
        $cash = Payment::factory()->for($this->service)->create(['method' => 'cash', 'reference' => null]);
        $this->assertNull(RefundPayment::provider($cash));
    }

    public function test_the_refund_window_offers_the_gateway_and_gives_the_money_back_there(): void
    {
        $payment = $this->paidWithStripe();
        Http::fake([StripeApi::BASE_URL.'/refunds' => Http::response(['id' => 're_1', 'status' => 'pending'])]);
        $this->actingAs($this->admin);

        Livewire::test(PaymentsRelationManager::class, ['ownerRecord' => $this->service, 'pageClass' => EditService::class])
            ->mountTableAction('refund', $payment)
            ->assertTableActionDataSet(['to' => RefundPayment::AT_PROVIDER])
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors();

        $this->assertSame(-6000, (int) Payment::query()->where('refund_of', $payment->id)->value('amount_cents'));
        Http::assertSentCount(1);
    }

    public function test_paypal_refunds_and_disputes_are_read_from_paypals_api_before_the_bell_rings(): void
    {
        $payment = $this->paidWithPaypal();
        $this->dispute = ['dispute_id' => 'PP-D-1', 'status' => 'WAITING_FOR_SELLER_RESPONSE', 'reason' => 'MERCHANDISE_OR_SERVICE_NOT_RECEIVED',
            'dispute_amount' => ['currency_code' => 'EUR', 'value' => '60.00'], 'disputed_transactions' => [['seller_transaction_id' => 'CAP1']]];
        Http::fake([
            self::PAYPAL.'/v1/oauth2/token' => Http::response(['access_token' => 'A21token', 'expires_in' => 32400]),
            self::PAYPAL.'/v2/payments/refunds/REF1' => Http::response(['id' => 'REF1', 'status' => 'COMPLETED', 'amount' => ['currency_code' => 'EUR', 'value' => '25.00'],
                'seller_payable_breakdown' => ['total_refunded_amount' => ['currency_code' => 'EUR', 'value' => '25.00']],
                'links' => [['rel' => 'up', 'href' => self::PAYPAL.'/v2/payments/captures/CAP1']]]),
            self::PAYPAL.'/v1/customer/disputes/PP-D-1' => fn () => Http::response($this->dispute),
        ]);

        // The webhook's own amounts are not believed: what PayPal's API says counts
        $this->postJson('/webhooks/paypal', ['event_type' => 'PAYMENT.CAPTURE.REFUNDED', 'resource' => ['id' => 'REF1', 'amount' => ['value' => '60.00']]])->assertOk();
        $this->assertSame(2500, ProviderPaymentState::for($payment)->refunded_cents);
        $this->assertSame(1, ActivityLog::query()->where('event', 'payment.provider_refunded')->count());

        $this->postJson('/webhooks/paypal', ['event_type' => 'CUSTOMER.DISPUTE.CREATED', 'resource' => ['dispute_id' => 'PP-D-1']])->assertOk();
        $this->assertSame('Chargeback open', ProviderPaymentState::note($payment));

        $this->dispute = ['status' => 'RESOLVED', 'dispute_outcome' => ['outcome_code' => 'RESOLVED_SELLER_FAVOUR']] + $this->dispute;
        $this->postJson('/webhooks/paypal', ['event_type' => 'CUSTOMER.DISPUTE.RESOLVED', 'resource' => ['dispute_id' => 'PP-D-1']])->assertOk();
        $this->assertSame('Chargeback won', ProviderPaymentState::note($payment));

        $this->assertEqualsCanonicalizing(["Refunded at PayPal: {$this->notice->number}", "Chargeback: {$this->notice->number}", "Chargeback won: {$this->notice->number}"], $this->bell());
    }

    public function test_a_paypal_report_that_paypal_does_not_confirm_is_tried_again_later(): void
    {
        $this->paidWithPaypal();
        Http::fake([
            self::PAYPAL.'/v1/oauth2/token' => Http::response(['access_token' => 'A21token', 'expires_in' => 32400]),
            self::PAYPAL.'/*' => Http::response(['message' => 'Not found'], 404),
        ]);

        $this->postJson('/webhooks/paypal', ['event_type' => 'PAYMENT.CAPTURE.REFUNDED', 'resource' => ['id' => 'FAKE']])->assertStatus(503);
        $this->assertSame(0, ProviderPaymentState::query()->count());
    }

    public function test_refund_payment_gives_a_paypal_payment_back_at_paypal(): void
    {
        $payment = $this->paidWithPaypal();
        Http::fake([
            self::PAYPAL.'/v1/oauth2/token' => Http::response(['access_token' => 'A21token', 'expires_in' => 32400]),
            self::PAYPAL.'/v2/payments/captures/CAP1/refund' => Http::response(['id' => 'REF9', 'status' => 'COMPLETED'], 201),
        ]);

        $refund = app(RefundPayment::class)->handle($payment, RefundPayment::AT_PROVIDER);

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/v2/payments/captures/CAP1/refund')
            && $request['amount'] === ['value' => '60.00', 'currency_code' => 'EUR']
            && $request->header('PayPal-Request-Id')[0] === 'gaiacp-refund-payment-'.$payment->id);
        $this->assertSame(-6000, $refund->amount_cents);
        $this->assertStringContainsString('REF9', $refund->notes);
    }
}
