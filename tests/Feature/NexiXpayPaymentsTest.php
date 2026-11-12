<?php

namespace Tests\Feature;

use App\Enums\BillingCycle;
use App\Enums\InvoiceStatus;
use App\Enums\UserRole;
use App\Filament\Client\Resources\PaymentNotices\Pages\ViewPaymentNotice;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\ModuleLog;
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
use Plugins\NexiXpay\NexiXpayApi;
use Plugins\NexiXpay\NexiXpayPayment;
use Plugins\NexiXpay\NexiXpayPayments;
use Plugins\NexiXpay\NexiXpaySettings;
use Tests\TestCase;

/** The Nexi XPay plugin: card payments on Nexi's hosted page, recorded only from the order read back from Nexi's API. */
class NexiXpayPaymentsTest extends TestCase
{
    use RefreshDatabase;

    private const API = NexiXpayApi::TEST_URL.NexiXpayApi::PATH;

    private const TOKEN = 'sec-token-1';

    private Client $client;

    private Service $service;

    private Invoice $notice;

    /** The operations Nexi lists for the order when asked; null = Nexi does not know the order. */
    private ?array $operations = [];

    private bool $refuseKey = false;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config(['gaiacp.nexi_xpay' => ['mode' => 'test', 'test_api_key' => 'nexi-test-key', 'live_api_key' => null]]);
        $plugins = app(PluginManager::class);
        $plugins->enable($plugins->find('nexi-xpay'));

        $this->client = Client::factory()->create(['email' => 'maria@example.gr', 'first_name' => 'Μαρία', 'last_name' => 'Παπά']);
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
            self::API.'orders/hpp' => Http::response(['hostedPage' => 'https://xpaysandbox.nexigroup.com/monetaweb/page/hosted/1', 'securityToken' => self::TOKEN]),
            self::API.'payment_methods' => fn () => $this->refuseKey
                ? Http::response(['errors' => [['code' => 'GW0001', 'description' => 'Invalid API key']]], 401)
                : Http::response(['paymentMethods' => []]),
            self::API.'orders/*' => fn () => $this->operations === null
                ? Http::response(['errors' => [['code' => 'GW0004', 'description' => 'Order not found']]], 404)
                : Http::response(['orderStatus' => [], 'operations' => $this->operations]),
        ]);
    }

    private function actAsClient(?Client $client = null): void
    {
        $client ??= $this->client;
        $user = User::query()->where('client_id', $client->id)->first()
            ?? User::factory()->create(['role' => UserRole::Client, 'client_id' => $client->id, 'email' => 'u'.$client->id.'@example.gr']);
        $this->actingAs($user, 'client');
        Filament::setCurrentPanel('client');
    }

    /** "Pay by card (Nexi)" pressed: the payment it opened. */
    private function start(): NexiXpayPayment
    {
        $this->actAsClient();

        Livewire::test(ViewPaymentNotice::class, ['record' => $this->notice->getRouteKey()])
            ->callAction('payWithNexiXpay')
            ->assertRedirect('https://xpaysandbox.nexigroup.com/monetaweb/page/hosted/1');

        return NexiXpayPayment::sole();
    }

    private function authorised(string $result = 'AUTHORIZED', string $amount = '6000', string $currency = 'EUR'): void
    {
        $this->operations = [['orderId' => 'X', 'operationId' => 'OP1', 'operationType' => 'AUTHORIZATION', 'operationResult' => $result, 'operationAmount' => $amount, 'operationCurrency' => $currency]];
    }

    private function back(NexiXpayPayment $payment, bool $cancelled = false): TestResponse
    {
        return $this->get('/nexi-xpay/return?order='.$payment->order_id.($cancelled ? '&cancelled=1' : ''));
    }

    private function notify(NexiXpayPayment $payment, string $token = self::TOKEN): TestResponse
    {
        return $this->postJson('/webhooks/nexi-xpay', ['eventId' => 'E1', 'securityToken' => $token, 'operation' => ['orderId' => $payment->order_id, 'operationResult' => 'AUTHORIZED']]);
    }

    public function test_unpaid_notices_get_the_button_once_it_is_set_up(): void
    {
        $this->actAsClient();
        $page = fn () => Livewire::test(ViewPaymentNotice::class, ['record' => $this->notice->getRouteKey()]);

        $page()->assertActionVisible('payWithNexiXpay');

        config(['gaiacp.nexi_xpay.test_api_key' => null]);
        $page()->assertActionDoesNotExist('payWithNexiXpay');
        config(['gaiacp.nexi_xpay.test_api_key' => 'nexi-test-key']);

        $this->notice->update(['currency' => 'JPY']);
        $page()->assertActionHidden('payWithNexiXpay');

        $this->notice->update(['currency' => 'EUR', 'status' => InvoiceStatus::Paid, 'paid_on' => today()]);
        $page()->assertActionHidden('payWithNexiXpay');
    }

    public function test_it_is_off_until_switched_on_and_then_a_payment_method(): void
    {
        $plugins = app(PluginManager::class);
        $this->assertFalse($plugins->find('nexi-xpay')->enabledByDefault());
        $this->assertArrayHasKey('nexixpay', PaymentMethods::options());

        $plugins->disable($plugins->find('nexi-xpay'));
        $this->assertArrayNotHasKey('nexixpay', PaymentMethods::options());
        $this->postJson('/webhooks/nexi-xpay', [])->assertNotFound();
    }

    public function test_the_button_opens_a_hosted_page_for_what_the_notice_owes(): void
    {
        $payment = $this->start();

        $this->assertSame(6000, $payment->amount_cents);
        $this->assertSame(NexiXpayPayment::OPEN, $payment->status);
        $this->assertSame(self::TOKEN, $payment->security_token);
        $this->assertNotSame(self::TOKEN, $payment->getRawOriginal('security_token')); // stored encrypted
        $this->assertLessThanOrEqual(18, strlen($payment->order_id));
        $this->assertArrayNotHasKey('security_token', $payment->toArray());

        Http::assertSent(fn (Request $request) => $request->url() === self::API.'orders/hpp'
            && $request->hasHeader('X-Api-Key', 'nexi-test-key')
            && $request->hasHeader('Correlation-Id')
            && $request['order']['orderId'] === $payment->order_id
            && $request['order']['amount'] === '6000'
            && $request['order']['currency'] === 'EUR'
            && $request['order']['customerInfo']['cardHolderEmail'] === 'maria@example.gr'
            && $request['paymentSession']['actionType'] === 'PAY'
            && $request['paymentSession']['amount'] === '6000'
            && $request['paymentSession']['resultUrl'] === url('/nexi-xpay/return?order='.$payment->order_id)
            && $request['paymentSession']['cancelUrl'] === url('/nexi-xpay/return?order='.$payment->order_id.'&cancelled=1')
            && $request['paymentSession']['notificationUrl'] === url('/webhooks/nexi-xpay'));
    }

    public function test_coming_back_paid_records_it_once_after_reading_the_order(): void
    {
        $payment = $this->start();
        $this->authorised();

        $this->back($payment)->assertRedirect(url('/payment-notices/'.$this->notice->id));
        $this->back($payment);
        $this->notify($payment)->assertOk();

        $this->assertSame(NexiXpayPayment::PAID, $payment->fresh()->status);
        $this->assertSame('OP1', $payment->fresh()->operation_id);
        $this->assertSame(InvoiceStatus::Paid, $this->notice->fresh()->status);
        $paid = Payment::sole();
        $this->assertSame('nexixpay', $paid->method);
        $this->assertSame($payment->order_id, $paid->reference);
        $this->assertSame(6000, $paid->amount_cents);
        Http::assertSent(fn (Request $request) => $request->method() === 'GET' && $request->url() === self::API.'orders/'.$payment->order_id);
    }

    public function test_coming_back_unpaid_or_cancelled_records_nothing(): void
    {
        $payment = $this->start();
        $this->operations = null; // Nexi does not know it yet

        $this->back($payment);
        $this->assertSame(NexiXpayPayment::OPEN, $payment->fresh()->status);

        $this->back($payment, cancelled: true);
        $this->assertSame(NexiXpayPayment::CANCELLED, $payment->fresh()->status);
        $this->assertSame(0, Payment::count());
    }

    public function test_a_declined_card_is_failed_and_a_pending_one_waits(): void
    {
        $payment = $this->start();
        $this->authorised('PENDING');
        $this->back($payment);
        $this->assertSame(NexiXpayPayment::PENDING, $payment->fresh()->status);

        $this->authorised('DECLINED');
        app(NexiXpayPayments::class)->recheck();
        $this->assertSame(NexiXpayPayment::FAILED, $payment->fresh()->status);
        $this->assertSame(0, Payment::count());
    }

    public function test_the_notification_needs_the_payments_security_token_and_is_only_a_hint(): void
    {
        $payment = $this->start();

        // Not believed: Nexi's order has no authorisation.
        $this->notify($payment)->assertOk();
        $this->assertSame(NexiXpayPayment::OPEN, $payment->fresh()->status);

        $this->authorised();
        $this->notify($payment, 'forged')->assertStatus(400);
        $this->assertSame(NexiXpayPayment::OPEN, $payment->fresh()->status);

        $this->notify($payment)->assertOk()->assertJson(['outcome' => 'OK']);
        $this->assertSame(NexiXpayPayment::PAID, $payment->fresh()->status);
    }

    public function test_another_amount_or_a_notice_paid_meanwhile_is_left_for_an_admin(): void
    {
        $payment = $this->start();
        $this->authorised(amount: '600');
        $this->back($payment);

        $this->assertSame(NexiXpayPayment::UNMATCHED, $payment->fresh()->status);
        $this->assertSame(0, Payment::count());
        $this->assertTrue(ActivityLog::query()->where('event', 'payment.online_unmatched')->exists());

        $this->notice->update(['status' => InvoiceStatus::Paid, 'paid_on' => today()]);
        $second = NexiXpayPayment::create(['invoice_id' => $this->notice->id, 'order_id' => 'SECOND1', 'amount_cents' => 6000, 'currency' => 'EUR']);
        $this->authorised();
        app(NexiXpayPayments::class)->sync($second);
        $this->assertSame(NexiXpayPayment::UNMATCHED, $second->fresh()->status);
        $this->assertSame('The payment notice is no longer unpaid', $second->fresh()->error);
    }

    public function test_the_hourly_recheck_records_a_payment_whose_client_never_came_back_and_expires_old_ones(): void
    {
        $payment = $this->start();
        $this->travel(20)->minutes();
        $this->authorised();
        app(NexiXpayPayments::class)->recheck();
        $this->assertSame(NexiXpayPayment::PAID, $payment->fresh()->status);

        $old = NexiXpayPayment::create(['invoice_id' => $this->notice->id, 'order_id' => 'OLD1', 'amount_cents' => 6000, 'currency' => 'EUR']);
        $live = NexiXpayPayment::create(['invoice_id' => $this->notice->id, 'order_id' => 'LIVE1', 'live' => true, 'amount_cents' => 6000, 'currency' => 'EUR']);
        $this->operations = null;
        $this->travel(25)->hours();
        app(NexiXpayPayments::class)->recheck();

        $this->assertSame(NexiXpayPayment::EXPIRED, $old->fresh()->status);
        $this->assertSame(NexiXpayPayment::OPEN, $live->fresh()->status); // read only with its own mode's key
        $this->assertTrue(ActivityLog::query()->where('event', 'nexi-xpay.no_answer')->exists());
        Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '/orders/LIVE1'));
    }

    public function test_a_payment_whose_recording_was_cut_off_is_handed_to_an_admin(): void
    {
        $payment = $this->start();
        $payment->update(['status' => NexiXpayPayment::PROCESSING]);
        $this->travel(2)->hours();

        $this->assertSame(1, app(NexiXpayPayments::class)->flagStuck());
        $this->assertSame(NexiXpayPayment::UNMATCHED, $payment->fresh()->status);
    }

    public function test_the_return_is_only_for_the_notices_client(): void
    {
        $payment = $this->start();
        $this->authorised();
        $this->actAsClient(Client::factory()->create());

        $this->back($payment)->assertRedirect(Filament::getPanel('client')->getUrl());
        $this->assertSame(NexiXpayPayment::OPEN, $payment->fresh()->status);
    }

    public function test_the_settings_page_keeps_the_key_encrypted_and_checks_it(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(NexiXpaySettings::class)
            ->assertSet('data.nexi_xpay.test_api_key', null)
            ->fillForm(['nexi_xpay' => ['mode' => 'live', 'test_api_key' => '', 'live_api_key' => 'nexi-live-key']])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertNotSame('nexi-live-key', Setting::query()->find('nexi_xpay.live_api_key')->value);
        $this->assertSame('nexi-live-key', config('gaiacp.nexi_xpay.live_api_key'));
        $this->assertSame('nexi-test-key', config('gaiacp.nexi_xpay.test_api_key')); // blank kept it

        config(['gaiacp.nexi_xpay.mode' => 'test']);
        Livewire::test(NexiXpaySettings::class)->callAction('checkKey')->assertNotified(__('The test key works'));
        $this->refuseKey = true;
        Livewire::test(NexiXpaySettings::class)->callAction('checkKey')->assertNotified(__('Nexi refused the key'));
    }

    public function test_the_module_log_masks_the_key_and_the_token(): void
    {
        config(['gaiacp.debug.module_log' => true]);
        $this->start();

        $this->assertSame(1, ModuleLog::query()->where('module', 'nexi-xpay')->count());
        $logged = ModuleLog::query()->get()->toJson();
        $this->assertStringNotContainsString('nexi-test-key', $logged);
        $this->assertStringNotContainsString(self::TOKEN, $logged);
    }
}
