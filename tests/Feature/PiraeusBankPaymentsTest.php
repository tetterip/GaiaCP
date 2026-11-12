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
use Plugins\PiraeusBank\Paycenter;
use Plugins\PiraeusBank\PiraeusBankPayment;
use Plugins\PiraeusBank\PiraeusBankPayments;
use Plugins\PiraeusBank\PiraeusBankSettings;
use Tests\TestCase;

/** The Piraeus Bank plugin: a ticket from the bank, its redirect, and a payment recorded only from an answer signed with the ticket. */
class PiraeusBankPaymentsTest extends TestCase
{
    use RefreshDatabase;

    private const TICKET = 'a1b2c3d4e5f60718293a4b5c6d7e8f90';

    /** IssueNewTicket's ResultCode, as the fake bank answers it. */
    private string $resultCode = '0';

    private Client $client;

    private Service $service;

    private Invoice $notice;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config(['gaiacp.piraeusbank' => ['merchant_id' => '2137000000', 'acquirer_id' => '14', 'pos_id' => '2130000000', 'username' => 'ME00001', 'password' => 'Πass w0rd']]);
        Http::fake([Paycenter::TICKET_URL => fn () => Http::response(
            '<?xml version="1.0" encoding="utf-8"?><soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body>'
            .'<IssueNewTicketResponse xmlns="http://piraeusbank.gr/paycenter/redirection"><IssueNewTicketResult>'
            .'<ResultCode>'.$this->resultCode.'</ResultCode><ResultDescription>'.($this->resultCode === '0' ? 'No Error' : 'Authentication Error').'</ResultDescription>'
            .'<TranTicket>'.($this->resultCode === '0' ? self::TICKET : '').'</TranTicket><Timestamp>2026-11-12T17:00:00</Timestamp><MinutesToExpiration>30</MinutesToExpiration>'
            .'</IssueNewTicketResult></IssueNewTicketResponse></soap:Body></soap:Envelope>',
            200, ['Content-Type' => 'text/xml; charset=utf-8'])]);
        $plugins = app(PluginManager::class);
        $plugins->enable($plugins->find('piraeusbank'));

        $this->client = Client::factory()->create([
            'email' => 'maria@example.gr', 'address1' => 'Ermou 1', 'city' => 'Αθήνα', 'postcode' => '10563', 'country' => 'GR', 'state' => 'I', 'phone' => '6912345678',
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
    private function start(): PiraeusBankPayment
    {
        $this->actAsClient();

        Livewire::test(ViewPaymentNotice::class, ['record' => $this->notice->getRouteKey()])
            ->callAction('payWithPiraeusBank')
            ->assertRedirect(url('/piraeusbank/pay/'.PiraeusBankPayment::sole()->reference));

        return PiraeusBankPayment::sole();
    }

    /**
     * The bank's answer as the client's browser posts it, signed with the ticket like the bank signs it (null = no HashKey).
     *
     * @param  array<string, string>  $fields
     */
    private function answer(PiraeusBankPayment $payment, array $fields = [], string $result = 'success', ?string $ticket = self::TICKET): TestResponse
    {
        $fields = array_merge([
            'SupportReferenceID' => '123456789', 'ResultCode' => '0', 'ResultDescription' => 'No Error', 'StatusFlag' => 'Success',
            'ResponseCode' => '00', 'ResponseDescription' => 'APPROVED OR COMPLETED SUCCESSFULLY', 'LanguageCode' => 'el-GR',
            'MerchantReference' => $payment->reference, 'TransactionDateTime' => '2026-11-12T17:01:00', 'TransactionId' => '987654',
            'CardType' => '1', 'PackageNo' => '7', 'ApprovalCode' => '112233', 'RetrievalRef' => '445566', 'AuthStatus' => '01',
            'Parameters' => '', 'PaymentMethod' => 'CARD',
        ], $fields);

        if ($ticket !== null) {
            $signed = ['2130000000', '14', $fields['MerchantReference'], $fields['ApprovalCode'], $fields['Parameters'], $fields['ResponseCode'],
                $fields['SupportReferenceID'], $fields['AuthStatus'], $fields['PackageNo'], $fields['StatusFlag']];
            $fields['HashKey'] = strtoupper(hash_hmac('sha256', $ticket.';'.implode(';', $signed), $ticket));
        }

        return $this->post('/webhooks/piraeusbank/'.$result, $fields);
    }

    public function test_the_ticket_request_and_its_answer(): void
    {
        config(['gaiacp.debug.module_log' => true]);
        $payment = $this->start();
        $this->assertSame(self::TICKET, $payment->ticket);
        $this->assertNotSame(self::TICKET, $payment->getRawOriginal('ticket')); // stored encrypted
        $this->assertArrayNotHasKey('ticket', $payment->toArray());
        $this->assertMatchesRegularExpression('/^'.$this->notice->id.'-[A-Z0-9]{16}$/', $payment->reference);

        Http::assertSent(function (Request $request) use ($payment) {
            $body = $request->body();

            return $request->url() === Paycenter::TICKET_URL
                && $request->hasHeader('SOAPAction', '"http://piraeusbank.gr/paycenter/redirection/IssueNewTicket"')
                && str_contains($body, '<IssueNewTicket xmlns="http://piraeusbank.gr/paycenter/redirection"><Request><Username>ME00001</Username>')
                && str_contains($body, '<Password>'.md5('Πass w0rd').'</Password>')
                && str_contains($body, '<MerchantReference>'.$payment->reference.'</MerchantReference><RequestType>02</RequestType>')
                && str_contains($body, '<Amount>60.00</Amount><CurrencyCode>978</CurrencyCode><Installments>1</Installments>')
                && str_contains($body, '<BillAddrCity>Αθήνα</BillAddrCity><BillAddrCountry>300</BillAddrCountry><BillAddrLine1>Ermou 1</BillAddrLine1><BillAddrPostCode>10563</BillAddrPostCode><BillAddrState>I</BillAddrState>')
                && str_contains($body, '<MobilePhone>30-6912345678</MobilePhone>');
        });

        $this->get('/piraeusbank/pay/'.$payment->reference)
            ->assertOk()
            ->assertSee('action="'.Paycenter::PAY_URL.'"', false)
            ->assertSee('name="MerchantReference" value="'.$payment->reference.'"', false)
            ->assertSee('name="LanguageCode" value="en-US"', false)
            ->assertDontSee(self::TICKET);

        // The module log never shows the password or the ticket
        $this->assertSame(1, ModuleLog::query()->where('module', 'piraeusbank')->count());
        $this->assertSame(0, ModuleLog::query()->where('request', 'like', '%'.md5('Πass w0rd').'%')->orWhere('response', 'like', '%'.self::TICKET.'%')->count());
    }

    public function test_helpers_match_the_woocommerce_plugins(): void
    {
        $this->assertSame(['type' => 'mobile', 'number' => '30-6912345678'], Paycenter::greekPhone('+30 691 234 5678', 'DE'));
        $this->assertSame(['type' => 'home', 'number' => '30-2101234567'], Paycenter::greekPhone('210 1234567', 'GR'));
        $this->assertNull(Paycenter::greekPhone('210 1234567', 'DE'));
        $this->assertSame('300', Paycenter::countryCode('gr'));
        $this->assertSame('040', Paycenter::countryCode('AT'));
        $this->assertSame('', Paycenter::countryCode('XX'));
        $this->assertSame('el-GR', Paycenter::language('el'));
        $this->assertSame('en-US', Paycenter::language('fr'));

        // The older plain SHA-256 HashKey is accepted too; both need the ticket.
        $answer = ['MerchantReference' => 'R', 'ResponseCode' => '00', 'StatusFlag' => 'Success'];
        $plain = strtoupper(hash('sha256', 'T'.implode('', ['P', 'A', 'R', '', '', '00', '', '', '', 'Success'])));
        $this->assertTrue(Paycenter::verify($answer + ['HashKey' => $plain], 'T', 'P', 'A'));
        $this->assertFalse(Paycenter::verify($answer + ['HashKey' => $plain], 'other', 'P', 'A'));
        $this->assertFalse(Paycenter::verify($answer + ['HashKey' => ''], 'T', 'P', 'A'));
    }

    public function test_unpaid_notices_get_the_button_once_it_is_set_up(): void
    {
        $this->actAsClient();
        $page = fn () => Livewire::test(ViewPaymentNotice::class, ['record' => $this->notice->getRouteKey()]);

        $page()->assertActionVisible('payWithPiraeusBank');

        config(['gaiacp.piraeusbank.password' => null]);
        $page()->assertActionDoesNotExist('payWithPiraeusBank');
        config(['gaiacp.piraeusbank.password' => 'x']);

        $this->notice->update(['currency' => 'JPY']);
        $page()->assertActionHidden('payWithPiraeusBank');
        $this->notice->update(['currency' => 'USD']);
        $page()->assertActionVisible('payWithPiraeusBank');

        $plugins = app(PluginManager::class);
        $this->assertFalse($plugins->find('piraeusbank')->enabledByDefault());
        $this->assertArrayHasKey('piraeusbank', PaymentMethods::options());
        $plugins->disable($plugins->find('piraeusbank'));
        $page()->assertActionDoesNotExist('payWithPiraeusBank');
        $this->post('/webhooks/piraeusbank/success')->assertNotFound();
    }

    public function test_a_refused_ticket_shows_a_message_and_opens_nothing(): void
    {
        $this->resultCode = '100';
        $this->actAsClient();

        Livewire::test(ViewPaymentNotice::class, ['record' => $this->notice->getRouteKey()])
            ->callAction('payWithPiraeusBank')
            ->assertNoRedirect()
            ->assertNotified(__('Paying by card is not available right now. Please try again later.'));

        $this->assertSame(PiraeusBankPayment::FAILED, PiraeusBankPayment::sole()->status);
        $this->assertStringContainsString('Authentication Error', PiraeusBankPayment::sole()->error);
        $this->assertDatabaseHas('activity_logs', ['event' => 'piraeusbank.ticket_failed']);
    }

    public function test_a_signed_approval_records_the_payment_once(): void
    {
        $payment = $this->start();
        $before = $this->service->next_due_date;

        $this->answer($payment)->assertRedirect(url('/piraeusbank/back/'.$payment->reference));

        $recorded = Payment::sole();
        $this->assertSame('piraeusbank', $recorded->method);
        $this->assertSame('987654', $recorded->reference);
        $this->assertSame(InvoiceStatus::Paid, $this->notice->fresh()->status);
        $this->assertTrue($this->service->fresh()->next_due_date->equalTo($before->addYear()));
        $this->assertSame(PiraeusBankPayment::PAID, $payment->fresh()->status);
        $this->assertSame('123456789', $payment->fresh()->support_reference);

        // Again, as "previously processed" (ResponseCode 11), or a failure after it: nothing changes.
        $this->answer($payment);
        $this->answer($payment, ['ResponseCode' => '11']);
        $this->answer($payment, ['ResultCode' => '1', 'ResponseCode' => '05'], 'fail', null);
        $this->assertSame(1, Payment::count());
        $this->assertSame(PiraeusBankPayment::PAID, $payment->fresh()->status);

        $this->get('/piraeusbank/back/'.$payment->reference)->assertRedirect(url("/payment-notices/{$this->notice->id}"));
    }

    public function test_an_approval_not_signed_with_the_ticket_changes_nothing(): void
    {
        $payment = $this->start();

        $this->answer($payment, ticket: 'guessed');
        $this->answer($payment, ticket: null); // no HashKey at all
        $this->get('/webhooks/piraeusbank/success?MerchantReference='.$payment->reference.'&ResultCode=0&ResponseCode=00');
        $this->answer($payment, ['MerchantReference' => $this->notice->id.'-MADEUP']);

        $this->assertSame(0, Payment::count());
        $this->assertSame(PiraeusBankPayment::OPEN, $payment->fresh()->status);
        $this->assertDatabaseHas('activity_logs', ['event' => 'piraeusbank.hash_failed']);
    }

    public function test_declines_cancels_and_a_pending_iris_transfer(): void
    {
        $payment = $this->start();
        // A decline on the fail address, without a HashKey as the bank sends them
        $this->answer($payment, ['ResultCode' => '0', 'ResponseCode' => '05', 'StatusFlag' => 'Failure'], 'fail', null)->assertRedirect(url('/piraeusbank/back/'.$payment->reference));
        $this->assertSame(PiraeusBankPayment::FAILED, $payment->fresh()->status);

        $this->actAsClient();
        Livewire::test(ViewPaymentNotice::class, ['record' => $this->notice->getRouteKey()])->callAction('payWithPiraeusBank');
        $second = PiraeusBankPayment::query()->latest('id')->first();
        $this->get('/webhooks/piraeusbank/cancel?MerchantReference='.$second->reference);
        $this->assertSame(PiraeusBankPayment::CANCELLED, $second->fresh()->status);

        Livewire::test(ViewPaymentNotice::class, ['record' => $this->notice->getRouteKey()])->callAction('payWithPiraeusBank');
        $iris = PiraeusBankPayment::query()->latest('id')->first();
        $this->answer($iris, ['ResponseCode' => '09', 'PaymentMethod' => 'IRIS', 'CardType' => '15'], 'fail');
        $this->assertSame(PiraeusBankPayment::PENDING, $iris->fresh()->status);
        $this->assertDatabaseHas('activity_logs', ['event' => 'payment.online_unmatched']);
        $this->assertSame(0, Payment::count());

        // A pending transfer is neither expired nor failed later.
        $this->travel(3)->hours();
        app(PiraeusBankPayments::class)->expire();
        $this->assertSame(PiraeusBankPayment::PENDING, $iris->fresh()->status);
    }

    public function test_a_notice_paid_meanwhile_is_left_for_an_admin(): void
    {
        $payment = $this->start();
        $this->notice->update(['status' => InvoiceStatus::Paid, 'paid_on' => today()]);

        $this->answer($payment);

        $this->assertSame(0, Payment::count());
        $this->assertSame(PiraeusBankPayment::UNMATCHED, $payment->fresh()->status);
    }

    public function test_payments_without_an_answer_expire_and_a_late_approval_still_counts(): void
    {
        $payment = $this->start();
        $this->travel(3)->hours();

        $this->assertSame(1, app(PiraeusBankPayments::class)->expire());
        $this->assertSame(PiraeusBankPayment::EXPIRED, $payment->fresh()->status);

        $this->answer($payment);
        $this->assertSame(PiraeusBankPayment::PAID, $payment->fresh()->status);
    }

    public function test_the_pay_page_is_only_for_the_notices_client(): void
    {
        $payment = $this->start();

        $this->actAsClient(Client::factory()->create());
        $this->get('/piraeusbank/pay/'.$payment->reference)->assertNotFound();
    }

    public function test_the_settings_page_keeps_the_password_and_names_the_addresses(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(PiraeusBankSettings::class)
            ->assertSet('data.piraeusbank.password', null)
            ->assertSchemaStateSet(['success_url' => url('/webhooks/piraeusbank/success'), 'failure_url' => url('/webhooks/piraeusbank/fail'), 'backlink_url' => url('/webhooks/piraeusbank/cancel')])
            ->fillForm(['piraeusbank' => ['merchant_id' => '1', 'acquirer_id' => '14', 'pos_id' => '2', 'username' => 'U', 'password' => 'NewPass']])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertNotSame('NewPass', Setting::query()->find('piraeusbank.password')->value);
        $this->assertSame('NewPass', config('gaiacp.piraeusbank.password'));

        Livewire::test(PiraeusBankSettings::class)
            ->fillForm(['piraeusbank' => ['merchant_id' => '1', 'acquirer_id' => '14', 'pos_id' => '2', 'username' => 'U', 'password' => '']])
            ->call('save');
        $this->assertSame('NewPass', config('gaiacp.piraeusbank.password'));
    }
}
