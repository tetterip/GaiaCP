<?php

namespace Tests\Feature;

use App\Enums\BillingCycle;
use App\Enums\InvoiceStatus;
use App\Enums\UserRole;
use App\Filament\Client\Resources\PaymentNotices\Pages\ViewPaymentNotice;
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
use Plugins\MastercardGateway\MastercardApi;
use Plugins\MastercardGateway\MastercardPayment;
use Plugins\MastercardGateway\MastercardPayments;
use Plugins\MastercardGateway\MastercardSettings;
use Tests\TestCase;

/** The Mastercard Gateway plugin: hosted checkout, recorded only from the order read back from the gateway's API. */
class MastercardPaymentsTest extends TestCase
{
    use RefreshDatabase;

    private const API = 'https://eu-gateway.mastercard.com/api/rest/version/100/merchant/TESTGAIA01/';

    private const PASSWORD = 'mpgs-api-pass';

    private Client $client;

    private Service $service;

    private Invoice $notice;

    /** The order as the gateway gives it when asked; null = the gateway does not know it (nothing paid yet). */
    private ?array $order = null;

    private bool $refuse = false;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config(['gaiacp.mastercard' => [
            'display_name' => 'Τράπεζα', 'host' => 'eu', 'custom_host' => null, 'mode' => 'test',
            'test_merchant_id' => 'TESTGAIA01', 'test_api_password' => self::PASSWORD, 'live_merchant_id' => null, 'live_api_password' => null,
        ]]);
        $plugins = app(PluginManager::class);
        $plugins->enable($plugins->find('mastercard'));

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
            self::API.'session' => Http::response(['result' => 'SUCCESS', 'session' => ['id' => 'SESSION0001', 'updateStatus' => 'SUCCESS'], 'successIndicator' => 'ind-secret-1']),
            self::API.'paymentOptionsInquiry' => fn () => $this->refuse
                ? Http::response(['result' => 'ERROR', 'error' => ['cause' => 'INVALID_REQUEST', 'explanation' => 'Invalid credentials.']], 401)
                : Http::response(['result' => 'SUCCESS', 'paymentTypes' => []]),
            self::API.'order/*' => fn () => $this->order === null
                ? Http::response(['result' => 'ERROR', 'error' => ['cause' => 'INVALID_REQUEST', 'explanation' => 'Unable to find order for given ID']], 400)
                : Http::response($this->order),
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

    private function start(): MastercardPayment
    {
        $this->actAsClient();

        Livewire::test(ViewPaymentNotice::class, ['record' => $this->notice->getRouteKey()])
            ->callAction('payWithMastercard')
            ->assertRedirect(url('/mastercard/pay/'.MastercardPayment::sole()->order_id));

        return MastercardPayment::sole();
    }

    private function captured(string $amount = '60.00', string $currency = 'EUR', string $status = 'CAPTURED', string $result = 'SUCCESS'): void
    {
        $this->order = ['result' => $result, 'status' => $status, 'amount' => (float) $amount, 'currency' => $currency, 'totalCapturedAmount' => (float) $amount,
            'transaction' => [['result' => $result, 'transaction' => ['id' => '1', 'type' => 'PAYMENT']]]];
    }

    private function back(MastercardPayment $payment): TestResponse
    {
        return $this->get('/mastercard/return?order='.$payment->order_id.'&resultIndicator=ind-secret-1');
    }

    public function test_unpaid_notices_get_the_button_with_the_name_once_it_is_set_up(): void
    {
        $this->actAsClient();
        $page = fn () => Livewire::test(ViewPaymentNotice::class, ['record' => $this->notice->getRouteKey()]);

        $page()->assertActionVisible('payWithMastercard');
        $this->get('/payment-notices/'.$this->notice->id)->assertSee('Pay by card (Τράπεζα)');

        config(['gaiacp.mastercard.test_api_password' => null]);
        $page()->assertActionDoesNotExist('payWithMastercard');
        config(['gaiacp.mastercard.test_api_password' => self::PASSWORD, 'gaiacp.mastercard.host' => 'custom', 'gaiacp.mastercard.custom_host' => 'https://']);
        $page()->assertActionDoesNotExist('payWithMastercard');
        config(['gaiacp.mastercard.host' => 'eu']);

        $this->notice->update(['currency' => 'KWD']);
        $page()->assertActionHidden('payWithMastercard');
    }

    public function test_it_is_off_until_switched_on_and_then_a_payment_method(): void
    {
        $plugins = app(PluginManager::class);
        $this->assertFalse($plugins->find('mastercard')->enabledByDefault());
        $this->assertArrayHasKey('mastercard', PaymentMethods::options());

        $plugins->disable($plugins->find('mastercard'));
        $this->assertArrayNotHasKey('mastercard', PaymentMethods::options());
        $this->actAsClient();
        $this->get('/mastercard/return?order=X')->assertNotFound();
    }

    public function test_the_button_opens_a_checkout_session_and_the_page_shows_it(): void
    {
        $payment = $this->start();

        $this->assertSame('SESSION0001', $payment->session_id);
        $this->assertSame('ind-secret-1', $payment->success_indicator);
        $this->assertNotSame('ind-secret-1', $payment->getRawOriginal('success_indicator'));
        Http::assertSent(fn (Request $request) => $request->url() === self::API.'session'
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('merchant.TESTGAIA01:'.self::PASSWORD))
            && $request['apiOperation'] === 'INITIATE_CHECKOUT'
            && $request['order']['id'] === $payment->order_id
            && $request['order']['amount'] === '60.00'
            && $request['order']['currency'] === 'EUR'
            && $request['interaction']['operation'] === 'PURCHASE'
            && $request['interaction']['returnUrl'] === url('/mastercard/return?order='.$payment->order_id));

        $this->get('/mastercard/pay/'.$payment->order_id)
            ->assertOk()
            ->assertSee('https://eu-gateway.mastercard.com/static/checkout/checkout.min.js', false)
            ->assertSee('"SESSION0001"', false);

        // Only for the notice's client
        $this->actAsClient(Client::factory()->create());
        $this->get('/mastercard/pay/'.$payment->order_id)->assertNotFound();
    }

    public function test_coming_back_paid_records_it_once_after_reading_the_order(): void
    {
        $payment = $this->start();
        $this->captured();

        $this->back($payment)->assertRedirect(url('/payment-notices/'.$this->notice->id));
        $this->back($payment);
        app(MastercardPayments::class)->recheck();

        $this->assertSame(MastercardPayment::PAID, $payment->fresh()->status);
        $paid = Payment::sole();
        $this->assertSame('mastercard', $paid->method);
        $this->assertSame($payment->order_id, $paid->reference);
        $this->assertSame(InvoiceStatus::Paid, $this->notice->fresh()->status);
    }

    public function test_the_result_indicator_alone_records_nothing(): void
    {
        $payment = $this->start();

        // The gateway knows no such order: the right indicator is not enough.
        $this->back($payment);
        $this->assertSame(MastercardPayment::OPEN, $payment->fresh()->status);
        $this->assertSame(0, Payment::count());
    }

    public function test_a_declined_card_is_failed_but_a_later_capture_still_counts(): void
    {
        $payment = $this->start();
        $this->captured(status: 'FAILED', result: 'FAILURE');
        $this->back($payment);
        $this->assertSame(MastercardPayment::FAILED, $payment->fresh()->status);

        $this->captured();
        app(MastercardPayments::class)->sync($payment->fresh());
        $this->assertSame(MastercardPayment::PAID, $payment->fresh()->status);
    }

    public function test_another_amount_is_left_for_an_admin(): void
    {
        $payment = $this->start();
        $this->captured(amount: '6.00');
        $this->back($payment);

        $this->assertSame(MastercardPayment::UNMATCHED, $payment->fresh()->status);
        $this->assertSame(0, Payment::count());
        $this->assertDatabaseHas('activity_logs', ['event' => 'payment.online_unmatched']);
    }

    public function test_the_hourly_recheck_records_late_payments_and_expires_old_ones(): void
    {
        $payment = $this->start();
        $this->travel(20)->minutes();
        $this->captured();
        app(MastercardPayments::class)->recheck();
        $this->assertSame(MastercardPayment::PAID, $payment->fresh()->status);

        $old = MastercardPayment::create(['invoice_id' => $this->notice->id, 'order_id' => 'OLD1', 'amount_cents' => 6000, 'currency' => 'EUR']);
        $this->order = null;
        $this->travel(25)->hours();
        app(MastercardPayments::class)->recheck();
        $this->assertSame(MastercardPayment::EXPIRED, $old->fresh()->status);
        $this->assertDatabaseHas('activity_logs', ['event' => 'mastercard.no_answer']);
    }

    public function test_amounts_go_to_and_from_the_gateway_without_floats(): void
    {
        $this->assertSame('60.05', MastercardPayments::value(6005));
        $this->assertSame(6000, MastercardPayments::cents(60));
        $this->assertSame(6050, MastercardPayments::cents(60.5));
        $this->assertSame(6005, MastercardPayments::cents('60.05'));
        $this->assertNull(MastercardPayments::cents('60,05'));
    }

    public function test_a_custom_gateway_address_is_used(): void
    {
        config(['gaiacp.mastercard.host' => 'custom', 'gaiacp.mastercard.custom_host' => 'https://ibanke.gateway.mastercard.com/']);

        $this->assertSame('ibanke.gateway.mastercard.com', MastercardApi::host());
        $this->assertSame('https://ibanke.gateway.mastercard.com/static/checkout/checkout.min.js', MastercardApi::checkoutScript());
    }

    public function test_the_settings_page_keeps_the_password_encrypted_and_checks_it(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(MastercardSettings::class)
            ->assertSet('data.mastercard.test_api_password', null)
            ->fillForm(['mastercard' => ['mode' => 'test', 'host' => 'custom', 'custom_host' => 'mybank.gateway.mastercard.com', 'test_merchant_id' => 'TESTGAIA01', 'test_api_password' => '', 'live_merchant_id' => 'GAIA01', 'live_api_password' => 'live-pass']])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertNotSame('live-pass', Setting::query()->find('mastercard.live_api_password')->value);
        $this->assertSame('live-pass', config('gaiacp.mastercard.live_api_password'));
        $this->assertSame(self::PASSWORD, config('gaiacp.mastercard.test_api_password')); // blank kept it
        $this->assertSame('mybank.gateway.mastercard.com', config('gaiacp.mastercard.custom_host'));

        config(['gaiacp.mastercard.host' => 'eu']);
        Livewire::test(MastercardSettings::class)->callAction('checkDetails')->assertNotified(__('The test details work'));
        $this->refuse = true;
        Livewire::test(MastercardSettings::class)->callAction('checkDetails')->assertNotified(__('The gateway refused the details'));
    }

    public function test_the_module_log_masks_the_password_and_the_indicator(): void
    {
        config(['gaiacp.debug.module_log' => true]);
        $this->start();

        $this->assertSame(1, ModuleLog::query()->where('module', 'mastercard')->count());
        $logged = ModuleLog::query()->get()->toJson();
        $this->assertStringNotContainsString(self::PASSWORD, $logged);
        $this->assertStringNotContainsString('ind-secret-1', $logged);
        $this->assertStringNotContainsString(base64_encode('merchant.TESTGAIA01:'.self::PASSWORD), $logged);
    }
}
