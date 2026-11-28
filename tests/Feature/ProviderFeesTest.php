<?php

namespace Tests\Feature;

use App\Actions\RecordInvoicePayment;
use App\Enums\BillingCycle;
use App\Filament\Clusters\Reports\Pages\AnnualIncome;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Service;
use App\Models\User;
use App\Payments\ProviderFees;
use App\Plugins\PluginManager;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Plugins\PayPal\PayPalApi;
use Plugins\PayPal\PayPalOrder;
use Plugins\PayPal\PayPalPayments;
use Plugins\Stripe\StripeApi;
use Plugins\Stripe\StripeCheckout;
use Plugins\Stripe\StripeCheckouts;
use Tests\TestCase;

/** What Stripe and PayPal keep of a payment, stored on it for the income reports. */
class ProviderFeesTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private Invoice $notice;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config(['gaiacp.stripe' => ['mode' => 'test', 'test_secret_key' => 'sk_test_123', 'test_webhook_secret' => 'whsec_1', 'live_secret_key' => null, 'live_webhook_secret' => null]]);
        config(['gaiacp.paypal' => ['mode' => 'sandbox', 'sandbox_client_id' => 'AcLientId', 'sandbox_secret' => 'EsEcret', 'live_client_id' => null, 'live_secret' => null]]);
        $plugins = app(PluginManager::class);
        $plugins->enable($plugins->find('paypal'));

        $this->client = Client::factory()->create();
        $due = CarbonImmutable::today()->addDays(10);
        $this->notice = Invoice::create([
            'number' => Invoice::nextNumber(now()), 'client_id' => $this->client->id, 'date' => today(), 'due_date' => $due,
            'currency' => 'EUR', 'subtotal_cents' => 6000, 'tax_cents' => 0, 'total_cents' => 6000,
        ]);

        // Two lines, 40.00 and 20.00: one gateway payment, two payments here
        foreach ([4000, 2000] as $cents) {
            $service = Service::factory()->for($this->client)->create([
                'start_date' => $due->subYear(), 'next_due_date' => $due, 'billing_cycle' => BillingCycle::Annually, 'price_cents' => $cents,
            ]);
            $this->notice->items()->create([
                'service_id' => $service->id, 'description' => $service->label, 'due_date' => $due, 'period_end' => $due->addYear(),
                'amount_cents' => $cents, 'tax_cents' => 0, 'total_cents' => $cents,
            ]);
        }
    }

    /** @return list<int|null> */
    private function fees(): array
    {
        return Payment::query()->orderBy('id')->pluck('fee_cents')->all();
    }

    public function test_a_fee_is_shared_by_the_payments_of_one_gateway_payment_to_the_cent_and_stored_once(): void
    {
        app(RecordInvoicePayment::class)->handle($this->notice, CarbonImmutable::now(), 'stripe', 'pi_1');

        $this->assertSame(0, app(ProviderFees::class)->record('stripe', 'pi_1', 112, 'USD')); // not the payments' currency
        $this->assertSame([null, null], $this->fees());

        $this->assertSame(2, app(ProviderFees::class)->record('stripe', 'pi_1', 112, 'EUR'));
        $this->assertSame([75, 37], $this->fees()); // 74.67 + 37.33: the cent left over goes to the one that lost most to rounding

        $this->assertSame(0, app(ProviderFees::class)->record('stripe', 'pi_1', 500, 'EUR'));
        $this->assertSame([75, 37], $this->fees());
    }

    public function test_stripes_fee_is_read_after_the_payment_and_asked_again_when_it_was_not_ready(): void
    {
        $settled = false;
        Http::fake([StripeApi::BASE_URL.'/payment_intents/pi_1*' => function () use (&$settled) {
            return Http::response(['id' => 'pi_1', 'latest_charge' => ['id' => 'ch_1', 'balance_transaction' => $settled
                ? ['id' => 'txn_1', 'amount' => 6000, 'fee' => 112, 'currency' => 'eur']
                : null]]);
        }]);
        StripeCheckout::create(['invoice_id' => $this->notice->id, 'session_id' => 'cs_1', 'livemode' => false, 'amount_cents' => 6000, 'currency' => 'EUR']);

        app(StripeCheckouts::class)->complete(['id' => 'cs_1', 'livemode' => false, 'payment_status' => 'paid', 'amount_total' => 6000, 'currency' => 'eur', 'payment_intent' => 'pi_1']);
        $this->assertSame([null, null], $this->fees());

        $settled = true;
        $this->assertSame(1, app(StripeCheckouts::class)->fees());
        $this->assertSame([75, 37], $this->fees());
        $this->assertSame(0, app(StripeCheckouts::class)->fees()); // nothing left to ask
    }

    public function test_a_stripe_fee_settled_in_another_currency_is_converted_back_with_its_rate(): void
    {
        Http::fake([StripeApi::BASE_URL.'/payment_intents/pi_1*' => Http::response(['latest_charge' => ['balance_transaction' => [
            'amount' => 5100, 'fee' => 102, 'currency' => 'gbp', 'exchange_rate' => 0.85,
        ]]])]);
        StripeCheckout::create(['invoice_id' => $this->notice->id, 'session_id' => 'cs_1', 'livemode' => false, 'amount_cents' => 6000, 'currency' => 'EUR']);

        app(StripeCheckouts::class)->complete(['id' => 'cs_1', 'livemode' => false, 'payment_status' => 'paid', 'amount_total' => 6000, 'currency' => 'eur', 'payment_intent' => 'pi_1']);

        $this->assertSame(120, array_sum($this->fees())); // 1.02 GBP / 0.85
    }

    public function test_paypals_fee_comes_with_the_capture(): void
    {
        $api = PayPalApi::SANDBOX_URL;
        Http::fake([
            $api.'/v1/oauth2/token' => Http::response(['access_token' => 'A21token', 'expires_in' => 32400]),
            $api.'/v2/checkout/orders/ORDER1' => Http::response(['id' => 'ORDER1', 'status' => 'COMPLETED', 'purchase_units' => [['payments' => ['captures' => [[
                'id' => 'CAP1', 'status' => 'COMPLETED', 'amount' => ['currency_code' => 'EUR', 'value' => '60.00'],
                'seller_receivable_breakdown' => ['gross_amount' => ['currency_code' => 'EUR', 'value' => '60.00'], 'paypal_fee' => ['currency_code' => 'EUR', 'value' => '2.45']],
            ]]]]]]),
        ]);
        $order = PayPalOrder::create(['invoice_id' => $this->notice->id, 'order_id' => 'ORDER1', 'live' => false, 'amount_cents' => 6000, 'currency' => 'EUR']);

        app(PayPalPayments::class)->sync($order);

        $this->assertSame(PayPalOrder::PAID, $order->fresh()->status);
        $this->assertSame([163, 82], $this->fees());
    }

    public function test_annual_income_shows_the_fees_once_there_are_any(): void
    {
        $this->actingAs(User::factory()->create());
        app(RecordInvoicePayment::class)->handle($this->notice, CarbonImmutable::now(), 'stripe', 'pi_1');

        Livewire::test(AnnualIncome::class)->assertDontSee('Provider fees');

        app(ProviderFees::class)->record('stripe', 'pi_1', 112, 'EUR');
        Livewire::test(AnnualIncome::class)->assertSee('Provider fees')->assertSee('€1.12');
    }
}
