<?php

namespace Tests\Feature;

use App\Enums\AdminArea;
use App\Enums\BillingCycle;
use App\Enums\InvoiceStatus;
use App\Enums\UserRole;
use App\Filament\Client\Resources\PaymentNotices\Pages\ViewPaymentNotice;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use App\Payments\PaymentMethods;
use App\Plugins\PluginManager;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Plugins\PayPal\PayPalApi;
use Plugins\PayPal\PayPalOrder;
use Plugins\PayPal\PayPalPayments;
use Plugins\PayPal\PayPalSettings;
use Tests\TestCase;

/** The PayPal plugin: "Pay with PayPal" on the client's payment notices, taken and recorded once PayPal confirms. */
class PayPalPaymentsTest extends TestCase
{
    use RefreshDatabase;

    private const API = PayPalApi::SANDBOX_URL;

    private Client $client;

    private Service $service;

    private Invoice $notice;

    /** What PayPal says about ORDER1 when asked (GET), and what capturing it gives. */
    private array $remote = ['status' => 'PAYER_ACTION_REQUIRED'];

    private array $capture = ['id' => 'CAP1', 'status' => 'COMPLETED', 'amount' => ['currency_code' => 'EUR', 'value' => '60.00']];

    private int $captures = 0;

    /** PayPal refusing the app's keys, or failing to take the money. */
    private bool $refuseKeys = false;

    private bool $captureFails = false;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config(['gaiacp.paypal' => ['mode' => 'sandbox', 'sandbox_client_id' => 'AcLientId', 'sandbox_secret' => 'EsEcret', 'live_client_id' => null, 'live_secret' => null]]);
        $plugins = app(PluginManager::class);
        $plugins->enable($plugins->find('paypal'));

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

        Http::fake([
            self::API.'/v1/oauth2/token' => fn () => $this->refuseKeys
                ? Http::response(['error' => 'invalid_client', 'error_description' => 'Client Authentication failed'], 401)
                : Http::response(['access_token' => 'A21token', 'expires_in' => 32400]),
            self::API.'/v2/checkout/orders/ORDER1/capture' => function () {
                if ($this->captureFails) {
                    return Http::response(['message' => 'Service unavailable'], 503);
                }

                $this->captures++;

                return Http::response(['id' => 'ORDER1', 'status' => 'COMPLETED', 'purchase_units' => [['payments' => ['captures' => [$this->capture]]]]], 201);
            },
            self::API.'/v2/checkout/orders/ORDER1' => fn () => Http::response(['id' => 'ORDER1'] + $this->remote),
            self::API.'/v2/checkout/orders' => Http::response(['id' => 'ORDER1', 'status' => 'PAYER_ACTION_REQUIRED', 'links' => [
                ['rel' => 'self', 'href' => self::API.'/v2/checkout/orders/ORDER1'],
                ['rel' => 'payer-action', 'href' => 'https://www.sandbox.paypal.com/checkoutnow?token=ORDER1'],
            ]]),
        ]);
    }

    private function actAsClient(): void
    {
        $user = User::factory()->create(['role' => UserRole::Client, 'client_id' => $this->client->id, 'email' => $this->client->email]);
        $this->actingAs($user, 'client');
        Filament::setCurrentPanel('client');
    }

    /** "Pay with PayPal" pressed: PayPal order ORDER1. */
    private function start(): void
    {
        $this->actAsClient();

        Livewire::test(ViewPaymentNotice::class, ['record' => $this->notice->getRouteKey()])
            ->callAction('payWithPaypal')
            ->assertRedirect('https://www.sandbox.paypal.com/checkoutnow?token=ORDER1');
    }

    /** The client approved it at PayPal. */
    private function approve(): void
    {
        $this->remote = ['status' => 'APPROVED'];
    }

    private function webhook(string $type, array $resource): TestResponse
    {
        return $this->postJson('/webhooks/paypal', ['id' => 'WH-1', 'event_type' => $type, 'resource' => $resource]);
    }

    public function test_unpaid_notices_get_pay_with_paypal_once_it_is_set_up(): void
    {
        $this->actAsClient();
        $page = fn () => Livewire::test(ViewPaymentNotice::class, ['record' => $this->notice->getRouteKey()]);

        $page()->assertActionVisible('payWithPaypal');
        $this->get("/payment-notices/{$this->notice->id}")->assertSee('Pay with PayPal');

        config(['gaiacp.paypal.sandbox_secret' => null]);
        $page()->assertActionDoesNotExist('payWithPaypal');

        // The live mode has its own app.
        config(['gaiacp.paypal.mode' => 'live', 'gaiacp.paypal.live_client_id' => 'L1', 'gaiacp.paypal.live_secret' => 'L2']);
        $page()->assertActionVisible('payWithPaypal');

        $plugins = app(PluginManager::class);
        $plugins->disable($plugins->find('paypal'));
        $page()->assertActionDoesNotExist('payWithPaypal');
        $plugins->enable($plugins->find('paypal'));

        // A currency PayPal takes only in whole units (or not at all)
        $this->notice->update(['currency' => 'JPY']);
        $page()->assertActionHidden('payWithPaypal');

        $this->notice->update(['currency' => 'EUR', 'status' => InvoiceStatus::Paid, 'paid_on' => today()]);
        $page()->assertActionHidden('payWithPaypal');
    }

    public function test_it_is_off_until_switched_on_and_then_a_payment_method(): void
    {
        $plugins = app(PluginManager::class);
        $this->assertFalse($plugins->find('paypal')->enabledByDefault());
        $this->assertSame('PayPal', PaymentMethods::options()['paypal']);
        $this->assertNotSame('paypal', PaymentMethods::default()); // recorded by itself, never pre-selected

        $plugins->disable($plugins->find('paypal'));
        $this->assertArrayNotHasKey('paypal', PaymentMethods::options());
    }

    public function test_pay_with_paypal_opens_an_order_for_what_the_notice_owes(): void
    {
        $this->start();

        Http::assertSent(fn (Request $request) => $request->url() === self::API.'/v1/oauth2/token'
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('AcLientId:EsEcret')));
        Http::assertSent(function (Request $request) {
            return $request->url() === self::API.'/v2/checkout/orders'
                && $request->hasHeader('Authorization', 'Bearer A21token')
                && $request->hasHeader('PayPal-Request-Id')
                && $request['intent'] === 'CAPTURE'
                && $request['purchase_units'][0]['amount'] === ['currency_code' => 'EUR', 'value' => '60.00']
                && $request['purchase_units'][0]['custom_id'] === (string) $this->notice->id
                && str_contains($request['purchase_units'][0]['description'], $this->notice->number)
                && $request['payment_source']['paypal']['experience_context']['return_url'] === url('/paypal/return')
                && $request['payment_source']['paypal']['experience_context']['cancel_url'] === url("/payment-notices/{$this->notice->id}");
        });

        $order = PayPalOrder::sole();
        $this->assertSame('ORDER1', $order->order_id);
        $this->assertSame(6000, $order->amount_cents);
        $this->assertSame(PayPalOrder::OPEN, $order->status);
        $this->assertFalse($order->live);
        $this->assertSame(0, Payment::count());
    }

    public function test_paypal_refusing_shows_a_message_and_stays_on_the_notice(): void
    {
        $this->actAsClient();
        $this->refuseKeys = true;

        Livewire::test(ViewPaymentNotice::class, ['record' => $this->notice->getRouteKey()])
            ->callAction('payWithPaypal')
            ->assertNoRedirect()
            ->assertNotified(__('Paying with PayPal is not available right now. Please try again later.'));

        $this->assertSame(0, PayPalOrder::count());
    }

    public function test_coming_back_approved_takes_the_money_and_records_it_once(): void
    {
        $this->start();
        $this->approve();
        $before = $this->service->next_due_date;

        $this->get('/paypal/return?token=ORDER1&PayerID=P1')->assertRedirect("/payment-notices/{$this->notice->id}");

        Http::assertSent(fn (Request $request) => $request->url() === self::API.'/v2/checkout/orders/ORDER1/capture'
            && $request->hasHeader('PayPal-Request-Id', 'capture-ORDER1'));
        $payment = Payment::sole();
        $this->assertSame('paypal', $payment->method);
        $this->assertSame('CAP1', $payment->reference);
        $this->assertSame(6000, $payment->amount_cents);
        $this->assertSame(InvoiceStatus::Paid, $this->notice->fresh()->status);
        $this->assertTrue($this->service->fresh()->next_due_date->equalTo($before->addYear()));
        $this->assertSame(PayPalOrder::PAID, PayPalOrder::sole()->status);
        $this->assertDatabaseHas('activity_logs', ['event' => 'paypal.paid']);

        // PayPal's events arriving afterwards (and the page reloaded) change nothing.
        $this->remote = ['status' => 'COMPLETED', 'purchase_units' => [['payments' => ['captures' => [$this->capture]]]]];
        $this->webhook('CHECKOUT.ORDER.APPROVED', ['id' => 'ORDER1'])->assertOk();
        $this->webhook('PAYMENT.CAPTURE.COMPLETED', ['id' => 'CAP1', 'supplementary_data' => ['related_ids' => ['order_id' => 'ORDER1']]])->assertOk();
        $this->get('/paypal/return?token=ORDER1');
        $this->assertSame(1, Payment::count());
        $this->assertSame(1, $this->captures);
    }

    public function test_coming_back_without_approving_takes_nothing(): void
    {
        $this->start();

        $this->get('/paypal/return?token=ORDER1')->assertRedirect("/payment-notices/{$this->notice->id}");
        $this->get('/paypal/return?token=MADE_UP')->assertRedirect(Filament::getPanel('client')->getUrl());

        $this->assertSame(0, $this->captures);
        $this->assertSame(0, Payment::count());
        $this->assertSame(PayPalOrder::OPEN, PayPalOrder::sole()->status);
    }

    public function test_the_webhook_takes_an_order_the_client_approved_but_never_came_back_from(): void
    {
        $this->start();
        $this->approve();

        $this->webhook('CHECKOUT.ORDER.APPROVED', ['id' => 'ORDER1', 'status' => 'APPROVED'])->assertOk()->assertJson(['received' => true]);

        $this->assertSame(1, Payment::count());
        $this->assertSame(PayPalOrder::PAID, PayPalOrder::sole()->status);
    }

    public function test_the_webhook_is_only_a_hint_what_paypal_says_counts(): void
    {
        $this->start(); // PayPal says the order is not approved

        $this->webhook('CHECKOUT.ORDER.APPROVED', ['id' => 'ORDER1', 'status' => 'APPROVED'])->assertOk();
        $this->webhook('PAYMENT.CAPTURE.COMPLETED', ['id' => 'CAP1', 'status' => 'COMPLETED', 'supplementary_data' => ['related_ids' => ['order_id' => 'ORDER1']]])->assertOk();
        $this->webhook('CHECKOUT.ORDER.APPROVED', ['id' => 'NOT_OURS'])->assertOk();
        $this->postJson('/webhooks/paypal', ['event_type' => 'BILLING.PLAN.CREATED'])->assertOk();

        $this->assertSame(0, $this->captures);
        $this->assertSame(0, Payment::count());
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'NOT_OURS'));
    }

    public function test_a_payment_still_clearing_waits_for_paypal_to_complete_it(): void
    {
        $this->start();
        $this->approve();
        $this->capture['status'] = 'PENDING';

        $this->get('/paypal/return?token=ORDER1');
        $this->assertSame(0, Payment::count());
        $this->assertSame(PayPalOrder::PENDING, PayPalOrder::sole()->status);
        $this->assertSame('CAP1', PayPalOrder::sole()->capture_id);

        $this->capture['status'] = 'COMPLETED';
        $this->remote = ['status' => 'COMPLETED', 'purchase_units' => [['payments' => ['captures' => [$this->capture]]]]];
        $this->webhook('PAYMENT.CAPTURE.COMPLETED', ['id' => 'CAP1', 'supplementary_data' => ['related_ids' => ['order_id' => 'ORDER1']]])->assertOk();

        $this->assertSame(1, Payment::count());
        $this->assertSame(PayPalOrder::PAID, PayPalOrder::sole()->status);
        $this->assertSame(1, $this->captures);
    }

    public function test_a_denied_payment_is_closed_without_recording(): void
    {
        $this->start();
        $this->approve();
        $this->capture['status'] = 'DECLINED';

        $this->get('/paypal/return?token=ORDER1');

        $this->assertSame(PayPalOrder::FAILED, PayPalOrder::sole()->status);
        $this->assertSame(0, Payment::count());
    }

    public function test_a_notice_changed_before_the_money_is_taken_takes_nothing(): void
    {
        $this->start();
        $this->approve();
        $this->notice->update(['status' => InvoiceStatus::Paid, 'paid_on' => today()]); // paid by bank transfer meanwhile

        $this->get('/paypal/return?token=ORDER1')->assertRedirect("/payment-notices/{$this->notice->id}");

        $this->assertSame(0, $this->captures);
        $this->assertSame(0, Payment::count());
        $order = PayPalOrder::sole();
        $this->assertSame(PayPalOrder::CANCELLED, $order->status);
        $this->assertSame('The payment notice is no longer unpaid', $order->error);
        $this->assertDatabaseMissing('activity_logs', ['event' => 'payment.online_unmatched']);
    }

    public function test_a_notice_paid_while_the_payment_cleared_is_left_for_an_admin(): void
    {
        $this->start();
        $this->approve();
        $this->capture['status'] = 'PENDING';
        $this->get('/paypal/return?token=ORDER1');
        $this->notice->update(['status' => InvoiceStatus::Paid, 'paid_on' => today()]);

        $this->capture['status'] = 'COMPLETED';
        $this->remote = ['status' => 'COMPLETED', 'purchase_units' => [['payments' => ['captures' => [$this->capture]]]]];
        $this->webhook('PAYMENT.CAPTURE.COMPLETED', ['id' => 'CAP1', 'supplementary_data' => ['related_ids' => ['order_id' => 'ORDER1']]])->assertOk();

        $this->assertSame(0, Payment::count());
        $this->assertSame(PayPalOrder::UNMATCHED, PayPalOrder::sole()->status);
        $log = ActivityLog::query()->where('event', 'payment.online_unmatched')->sole();
        $this->assertSame($this->client->id, $log->client_id);
        $this->assertStringContainsString('PayPal', $log->description);
    }

    public function test_a_different_amount_taken_is_not_recorded(): void
    {
        $this->start();
        $this->approve();
        $this->capture['amount']['value'] = '1.00';

        $this->get('/paypal/return?token=ORDER1');

        $this->assertSame(0, Payment::count());
        $this->assertSame(PayPalOrder::UNMATCHED, PayPalOrder::sole()->status);
    }

    public function test_a_failed_capture_is_tried_again_later_and_the_webhook_is_retried(): void
    {
        $this->start();
        $this->approve();
        $this->captureFails = true;

        $this->get('/paypal/return?token=ORDER1')->assertRedirect("/payment-notices/{$this->notice->id}");
        $this->assertSame(PayPalOrder::OPEN, PayPalOrder::sole()->status);
        $this->webhook('CHECKOUT.ORDER.APPROVED', ['id' => 'ORDER1'])->assertStatus(503); // PayPal sends it again
        $this->assertSame(0, Payment::count());

        $this->captureFails = false;
        $this->webhook('CHECKOUT.ORDER.APPROVED', ['id' => 'ORDER1'])->assertOk();
        $this->assertSame(1, Payment::count());
    }

    public function test_an_order_whose_taking_was_cut_off_is_handed_to_an_admin(): void
    {
        $this->start();
        PayPalOrder::sole()->update(['status' => PayPalOrder::PROCESSING]); // the request died part way

        $this->assertSame(0, app(PayPalPayments::class)->flagStuck());

        $this->travel(61)->minutes();
        $this->assertSame(1, app(PayPalPayments::class)->flagStuck());
        $this->assertSame(PayPalOrder::UNMATCHED, PayPalOrder::sole()->status);
        $this->assertDatabaseHas('activity_logs', ['event' => 'payment.online_unmatched', 'client_id' => $this->client->id]);
    }

    public function test_amounts_go_to_and_from_paypal_without_floats(): void
    {
        $this->assertSame('60.00', PayPalPayments::value(6000));
        $this->assertSame('0.05', PayPalPayments::value(5));
        $this->assertSame('1234.5', substr(PayPalPayments::value(123450), 0, 6));
        $this->assertSame(6000, PayPalPayments::cents('60.00'));
        $this->assertSame(6050, PayPalPayments::cents('60.5'));
        $this->assertSame(6000, PayPalPayments::cents('60'));
        $this->assertNull(PayPalPayments::cents('60.001'));
        $this->assertNull(PayPalPayments::cents(60));
    }

    public function test_while_the_plugin_is_off_its_routes_are_404(): void
    {
        $this->start();
        $plugins = app(PluginManager::class);
        $plugins->disable($plugins->find('paypal'));

        $this->webhook('CHECKOUT.ORDER.APPROVED', ['id' => 'ORDER1'])->assertNotFound();
        $this->get('/paypal/return?token=ORDER1')->assertNotFound();
    }

    public function test_the_help_page_mentions_it_only_while_it_is_set_up(): void
    {
        $this->actAsClient();
        $this->get('/help')->assertOk()->assertSee('Pay with PayPal')->assertDontSee('Pay by card');

        config(['gaiacp.paypal.sandbox_client_id' => null]);
        $this->get('/help')->assertOk()->assertDontSee('Pay with PayPal');
    }

    public function test_the_settings_page_stores_the_secret_encrypted_and_shows_the_webhook(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(PayPalSettings::class)
            ->assertSet('data.paypal.sandbox_secret', null)
            ->assertSchemaStateSet(['webhook_url' => url('/webhooks/paypal')])
            ->assertSee('PAYMENT.CAPTURE.COMPLETED')
            ->fillForm(['paypal' => ['mode' => 'live', 'live_client_id' => 'LiveId', 'live_secret' => 'LiveSecret']])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertNotSame('LiveSecret', Setting::query()->find('paypal.live_secret')->value);
        $this->assertSame('LiveSecret', config('gaiacp.paypal.live_secret'));
        $this->assertSame('LiveId', config('gaiacp.paypal.live_client_id'));
        $this->assertSame('live', config('gaiacp.paypal.mode'));
        $this->assertSame('EsEcret', config('gaiacp.paypal.sandbox_secret')); // blank kept it
    }

    public function test_check_the_keys_asks_paypal_for_a_token(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(PayPalSettings::class)->callAction('checkKeys')->assertNotified(__('The sandbox keys work'));

        $this->refuseKeys = true;
        Livewire::test(PayPalSettings::class)->callAction('checkKeys')->assertNotified(__('PayPal refused the keys'));
    }

    public function test_admins_without_full_access_cant_open_the_settings(): void
    {
        User::factory()->create(); // the first admin is a super admin
        $this->actingAs(User::factory()->limitedTo([AdminArea::BusinessSettings])->create());

        $this->get(PayPalSettings::getUrl(panel: 'admin'))->assertForbidden();
    }
}
