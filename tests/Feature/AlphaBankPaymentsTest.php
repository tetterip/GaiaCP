<?php

namespace Tests\Feature;

use App\Enums\BillingCycle;
use App\Enums\InvoiceStatus;
use App\Enums\UserRole;
use App\Filament\Client\Resources\PaymentNotices\Pages\ViewPaymentNotice;
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
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Plugins\AlphaBank\AlphaBankPayment;
use Plugins\AlphaBank\AlphaBankPayments;
use Plugins\AlphaBank\AlphaBankSettings;
use Plugins\AlphaBank\Cardlink;
use Tests\TestCase;

/** The Alpha Bank plugin: card payments through Cardlink's redirect, recorded only from an answer the bank signed. */
class AlphaBankPaymentsTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'Cardlink3DSsecret';

    private Client $client;

    private Service $service;

    private Invoice $notice;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config(['gaiacp.alphabank' => ['mode' => 'test', 'merchant_id' => '0020000001', 'shared_secret' => self::SECRET]]);
        $plugins = app(PluginManager::class);
        $plugins->enable($plugins->find('alphabank'));

        $this->client = Client::factory()->create([
            'email' => 'maria@example.gr', 'address1' => 'Ermou 1', 'city' => 'Αθήνα', 'postcode' => '10563', 'country' => 'GR', 'state' => 'I',
        ]);
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

    private function actAsClient(?Client $client = null): void
    {
        $client ??= $this->client;
        $user = User::query()->where('client_id', $client->id)->first()
            ?? User::factory()->create(['role' => UserRole::Client, 'client_id' => $client->id, 'email' => 'u'.$client->id.'@example.gr']);
        $this->actingAs($user, 'client');
        Filament::setCurrentPanel('client');
    }

    /** "Pay by card" pressed: the payment it opened. */
    private function start(): AlphaBankPayment
    {
        $this->actAsClient();

        Livewire::test(ViewPaymentNotice::class, ['record' => $this->notice->getRouteKey()])
            ->callAction('payWithAlphaBank')
            ->assertRedirect(url('/alphabank/pay/'.AlphaBankPayment::sole()->reference));

        return AlphaBankPayment::sole();
    }

    /**
     * The bank's answer as the client's browser posts it, signed like the bank signs it.
     *
     * @param  array<string, string>  $fields
     */
    private function answer(AlphaBankPayment $payment, array $fields = [], string $result = 'success', ?string $secret = null): TestResponse
    {
        $fields = array_merge([
            'version' => '2', 'mid' => '0020000001', 'orderid' => $payment->reference, 'status' => 'CAPTURED',
            'orderAmount' => '60.00', 'currency' => 'EUR', 'paymentTotal' => '60.00', 'message' => 'OK  ', 'riskScore' => '',
            'payMethod' => 'visa', 'txId' => '911100000123', 'paymentRef' => '123456',
        ], $fields);
        $fields['digest'] = Cardlink::sign($fields, $secret ?? self::SECRET);

        return $this->call('POST', '/webhooks/alphabank/'.$result, [], [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'], http_build_query($fields));
    }

    public function test_the_digest_matches_the_woocommerce_plugins(): void
    {
        // base64(sha256(values joined . secret)), as WC_AlphaBank_Gateway_Base::calculate_digest()
        $fields = ['version' => '2', 'mid' => '1', 'orderid' => 'A1'];
        $this->assertSame(base64_encode(hash('sha256', '21A1'.self::SECRET, true)), Cardlink::sign($fields, self::SECRET));

        $this->assertTrue(Cardlink::verify($fields + ['digest' => Cardlink::sign($fields, self::SECRET)], self::SECRET));
        $this->assertFalse(Cardlink::verify($fields + ['digest' => Cardlink::sign($fields, 'other')], self::SECRET));
        $this->assertFalse(Cardlink::verify($fields + ['digest' => Cardlink::sign($fields, '')], ''));

        // Values are read exactly as posted: spaces and empty fields are signed too.
        $this->assertSame(['a' => ' x ', 'b' => '', 'c' => 'Αθήνα & co'], Cardlink::parse('a=+x+&b=&c='.urlencode('Αθήνα & co')));
        $this->assertSame(6000, Cardlink::cents('60'));
        $this->assertSame(6050, Cardlink::cents('60.5'));
        $this->assertNull(Cardlink::cents('60,00'));
        $this->assertSame('0.05', Cardlink::amount(5));
    }

    public function test_unpaid_notices_in_euro_get_the_button_once_it_is_set_up(): void
    {
        $this->actAsClient();
        $page = fn () => Livewire::test(ViewPaymentNotice::class, ['record' => $this->notice->getRouteKey()]);

        $page()->assertActionVisible('payWithAlphaBank');

        config(['gaiacp.alphabank.shared_secret' => null]);
        $page()->assertActionDoesNotExist('payWithAlphaBank');
        config(['gaiacp.alphabank.shared_secret' => self::SECRET]);

        $plugins = app(PluginManager::class);
        $plugins->disable($plugins->find('alphabank'));
        $page()->assertActionDoesNotExist('payWithAlphaBank');
        $plugins->enable($plugins->find('alphabank'));

        $this->notice->update(['currency' => 'USD']);
        $page()->assertActionHidden('payWithAlphaBank');

        $this->notice->update(['currency' => 'EUR', 'status' => InvoiceStatus::Paid, 'paid_on' => today()]);
        $page()->assertActionHidden('payWithAlphaBank');
    }

    public function test_it_is_off_until_switched_on_and_then_a_payment_method(): void
    {
        $plugins = app(PluginManager::class);
        $this->assertFalse($plugins->find('alphabank')->enabledByDefault());
        $this->assertArrayHasKey('alphabank', PaymentMethods::options());
        $this->assertNotSame('alphabank', PaymentMethods::default());

        $plugins->disable($plugins->find('alphabank'));
        $this->assertArrayNotHasKey('alphabank', PaymentMethods::options());
        $this->post('/webhooks/alphabank/success')->assertNotFound();
    }

    public function test_the_pay_page_posts_the_signed_form_to_the_bank(): void
    {
        $payment = $this->start();
        $this->assertSame(6000, $payment->amount_cents);
        $this->assertFalse($payment->live);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{1,50}$/', $payment->reference);

        $form = app(AlphaBankPayments::class)->form($payment);
        $this->assertSame(Cardlink::TEST_URL, $form['url']);
        $fields = $form['fields'];
        $this->assertSame(['version', 'mid', 'lang', 'orderid', 'orderDesc', 'orderAmount', 'currency', 'payerEmail', 'billCountry', 'billZip', 'billCity', 'billAddress', 'trType', 'confirmUrl', 'cancelUrl', 'digest'], array_keys($fields));
        $this->assertSame('60.00', $fields['orderAmount']);
        $this->assertSame('1', $fields['trType']);
        $this->assertSame(url('/webhooks/alphabank/success'), $fields['confirmUrl']);
        $digest = array_pop($fields);
        $this->assertSame(Cardlink::sign($fields, self::SECRET), $digest);

        $this->get('/alphabank/pay/'.$payment->reference)
            ->assertOk()
            ->assertSee('action="'.Cardlink::TEST_URL.'"', false)
            ->assertSee('name="digest" value="'.e($digest).'"', false);

        // Live mode posts to the live bank.
        config(['gaiacp.alphabank.mode' => 'live']);
        $this->assertSame(Cardlink::LIVE_URL, app(AlphaBankPayments::class)->form($payment)['url']);
    }

    public function test_the_pay_page_is_only_for_the_notices_client(): void
    {
        $payment = $this->start();

        $this->actAsClient(Client::factory()->create());
        $this->get('/alphabank/pay/'.$payment->reference)->assertNotFound();
        $this->get('/alphabank/pay/MADEUP')->assertNotFound();
    }

    public function test_missing_address_details_are_asked_for_first(): void
    {
        $this->client->update(['postcode' => null, 'city' => '']);
        $this->actAsClient();

        Livewire::test(ViewPaymentNotice::class, ['record' => $this->notice->getRouteKey()])
            ->callAction('payWithAlphaBank')
            ->assertNoRedirect()
            ->assertNotified(__('Please fill in your :fields in your profile first: the bank needs them to check the card.', ['fields' => __('City').', '.__('Postcode')]));

        $this->assertSame(0, AlphaBankPayment::count());
    }

    public function test_a_signed_paid_answer_records_the_payment_once(): void
    {
        $payment = $this->start();
        $before = $this->service->next_due_date;

        $this->answer($payment)->assertRedirect(url('/alphabank/back/'.$payment->reference));

        $recorded = Payment::sole();
        $this->assertSame('alphabank', $recorded->method);
        $this->assertSame('123456', $recorded->reference);
        $this->assertSame(6000, $recorded->amount_cents);
        $this->assertSame(InvoiceStatus::Paid, $this->notice->fresh()->status);
        $this->assertTrue($this->service->fresh()->next_due_date->equalTo($before->addYear()));
        $payment->refresh();
        $this->assertSame(AlphaBankPayment::PAID, $payment->status);
        $this->assertSame('911100000123', $payment->transaction_id);
        $this->assertSame('visa', $payment->pay_method);
        $this->assertDatabaseHas('activity_logs', ['event' => 'alphabank.paid']);

        // The same answer again (Back, reload), or a failure after it, changes nothing.
        $this->answer($payment);
        $this->answer($payment, ['status' => 'REFUSED'], 'failure');
        $this->assertSame(1, Payment::count());
        $this->assertSame(AlphaBankPayment::PAID, $payment->fresh()->status);

        $this->get('/alphabank/back/'.$payment->reference)->assertRedirect(url("/payment-notices/{$this->notice->id}"));
    }

    public function test_an_answer_that_is_not_signed_by_the_bank_changes_nothing(): void
    {
        $payment = $this->start();

        $this->answer($payment, secret: 'guessed')->assertRedirect(Filament::getPanel('client')->getUrl());
        $this->answer($payment, secret: '');

        $this->assertSame(0, Payment::count());
        $this->assertSame(AlphaBankPayment::OPEN, $payment->fresh()->status);
        $this->assertDatabaseHas('activity_logs', ['event' => 'alphabank.digest_failed']);

        // Without a saved secret nothing is believed, not even an answer signed with an empty one.
        config(['gaiacp.alphabank.shared_secret' => null]);
        $this->answer($payment, secret: '');
        $this->assertSame(0, Payment::count());

        // An answer for an orderid GaiaCP never opened
        config(['gaiacp.alphabank.shared_secret' => self::SECRET]);
        $this->answer($payment, ['orderid' => 'MADEUP']);
        $this->assertSame(0, Payment::count());
    }

    public function test_a_signed_answer_for_another_amount_is_left_for_an_admin(): void
    {
        $payment = $this->start();

        $this->answer($payment, ['orderAmount' => '0.60']);

        $this->assertSame(0, Payment::count());
        $payment->refresh();
        $this->assertSame(AlphaBankPayment::UNMATCHED, $payment->status);
        $this->assertSame('The bank took a different amount than the payment asked for', $payment->error);
        $this->assertDatabaseHas('activity_logs', ['event' => 'payment.online_unmatched']);
    }

    public function test_a_notice_paid_meanwhile_is_left_for_an_admin(): void
    {
        $payment = $this->start();
        $this->notice->update(['status' => InvoiceStatus::Paid, 'paid_on' => today()]);

        $this->answer($payment);

        $this->assertSame(0, Payment::count());
        $this->assertSame(AlphaBankPayment::UNMATCHED, $payment->fresh()->status);
        $this->assertSame('The payment notice is no longer unpaid', $payment->fresh()->error);
    }

    public function test_cancelled_and_refused_payments_are_closed(): void
    {
        $payment = $this->start();
        $this->answer($payment, ['status' => 'CANCELED', 'message' => 'Cancelled by user'], 'failure')->assertRedirect(url('/alphabank/back/'.$payment->reference));
        $this->assertSame(AlphaBankPayment::CANCELLED, $payment->fresh()->status);

        $this->notice->refresh();
        $this->actAsClient();
        Livewire::test(ViewPaymentNotice::class, ['record' => $this->notice->getRouteKey()])->callAction('payWithAlphaBank');
        $second = AlphaBankPayment::query()->latest('id')->first();
        $this->assertNotSame($payment->reference, $second->reference);

        $this->answer($second, ['status' => 'REFUSED'], 'failure');
        $this->assertSame(AlphaBankPayment::FAILED, $second->fresh()->status);
        $this->assertSame(0, Payment::count());

        // The pay page of a closed payment goes back to the notice.
        $this->get('/alphabank/pay/'.$second->reference)->assertRedirect(url("/payment-notices/{$this->notice->id}"));
    }

    public function test_payments_without_an_answer_expire_and_a_late_paid_answer_still_counts(): void
    {
        $payment = $this->start();
        $this->travel(3)->hours();

        $this->assertSame(1, app(AlphaBankPayments::class)->expire());
        $this->assertSame(AlphaBankPayment::EXPIRED, $payment->fresh()->status);
        $this->assertDatabaseHas('activity_logs', ['event' => 'alphabank.no_answer']);

        $this->answer($payment, ['status' => 'CANCELED'], 'failure');
        $this->assertSame(AlphaBankPayment::EXPIRED, $payment->fresh()->status);

        $this->answer($payment);
        $this->assertSame(AlphaBankPayment::PAID, $payment->fresh()->status);
        $this->assertSame(1, Payment::count());
    }

    public function test_the_settings_page_keeps_the_secret_and_names_the_answer_addresses(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(AlphaBankSettings::class)
            ->assertSet('data.alphabank.shared_secret', null)
            ->assertSchemaStateSet(['success_url' => url('/webhooks/alphabank/success'), 'failure_url' => url('/webhooks/alphabank/failure')])
            ->fillForm(['alphabank' => ['mode' => 'live', 'merchant_id' => '0020000009', 'shared_secret' => 'NewSecret']])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertNotSame('NewSecret', Setting::query()->find('alphabank.shared_secret')->value);
        $this->assertSame('NewSecret', config('gaiacp.alphabank.shared_secret'));
        $this->assertSame('live', config('gaiacp.alphabank.mode'));

        // A blank field keeps it, and it is never sent back to the browser.
        Livewire::test(AlphaBankSettings::class)
            ->assertSet('data.alphabank.shared_secret', null)
            ->fillForm(['alphabank' => ['mode' => 'live', 'merchant_id' => '0020000009', 'shared_secret' => '']])
            ->call('save');
        $this->assertSame('NewSecret', config('gaiacp.alphabank.shared_secret'));
        $this->assertSame('0020000009', config('gaiacp.alphabank.merchant_id'));
    }
}
