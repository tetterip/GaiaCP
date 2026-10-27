<?php

namespace Tests\Feature;

use App\Actions\PlaceOrder;
use App\Actions\RecordInvoicePayment;
use App\Actions\ReversePayment;
use App\Credit\AddFunds;
use App\Credit\ApplyCredit;
use App\Credit\ClientCredit;
use App\Enums\BillingCycle;
use App\Enums\CreditType;
use App\Enums\InvoiceStatus;
use App\Enums\MailTemplate;
use App\Enums\ServiceStatus;
use App\Enums\UserRole;
use App\Filament\Client\Pages\Credit;
use App\Filament\Client\Pages\Help;
use App\Filament\Client\Resources\PaymentNotices\Pages\ViewPaymentNotice;
use App\Filament\Client\Resources\PaymentNotices\PaymentNoticeResource;
use App\Filament\Resources\Clients\Pages\EditClient;
use App\Filament\Resources\Clients\RelationManagers\CreditRelationManager;
use App\Filament\Resources\Invoices\Pages\ViewInvoice;
use App\Invoices\GenerateInvoices;
use App\Mail\ClientMail;
use App\Models\Client;
use App\Models\CreditTransaction;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Plugins\Stripe\StripeCheckouts;
use RuntimeException;
use Tests\FakesWhm;
use Tests\TestCase;

class ClientCreditTest extends TestCase
{
    use FakesWhm, RefreshDatabase;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeWhm();
        Mail::fake();
        $this->travelTo('2026-10-19 10:00');
        config([
            'gaiacp.credit' => ['enabled' => true, 'min_deposit' => 10, 'max_deposit' => 300, 'max_balance' => 600, 'auto_apply' => true],
            'gaiacp.invoices.days_before' => 14,
            'gaiacp.tax.enabled' => false,
        ]);

        $this->client = Client::factory()->create();
        $this->service('2027-06-01'); // an active service, so funds can be added
    }

    private function service(string $due, int $price = 6000): Service
    {
        return Service::factory()->for($this->client)->create([
            'start_date' => CarbonImmutable::parse($due)->subYear(), 'next_due_date' => $due, 'price_cents' => $price,
        ]);
    }

    private function addCredit(int $cents): void
    {
        app(ClientCredit::class)->record($this->client, CreditType::Adjustment, $cents, 'test');
    }

    private function notice(Service ...$services): Invoice
    {
        $created = app(GenerateInvoices::class)->handle(CarbonImmutable::today());

        return collect($created)->first(fn (Invoice $i) => $i->items->pluck('service_id')->contains($services[0]->id))->refresh();
    }

    public function test_add_funds_creates_an_untaxed_notice_that_pays_into_the_balance(): void
    {
        config(['gaiacp.tax' => ['enabled' => true, 'inclusive' => false, 'rules' => [['name' => 'ΦΠΑ', 'rate' => 24, 'country' => null]]]]);

        $notice = app(AddFunds::class)->handle($this->client, 5000);

        $this->assertTrue($notice->credit);
        $this->assertSame([5000, 0, 5000], [$notice->subtotal_cents, $notice->tax_cents, $notice->total_cents]);
        $this->assertSame('2026-11-02', $notice->due_date->toDateString());
        $this->assertSame(['Add funds'], $notice->items->pluck('description')->all());
        $this->assertNotNull($notice->emailed_at, 'emailed with how to pay');

        app(RecordInvoicePayment::class)->handle($notice, CarbonImmutable::today(), 'bank_transfer', 'TX-1');

        $this->assertSame(InvoiceStatus::Paid, $notice->fresh()->status);
        $this->assertSame(5000, $this->client->fresh()->credit_cents);
        $this->assertSame(0, Payment::count(), 'a deposit is not a service payment');
        $this->actingAs(User::factory()->create())->get(ViewInvoice::getUrl(['record' => $notice]))->assertOk();
        $deposit = CreditTransaction::sole();
        $this->assertSame([CreditType::Deposit, 5000, 5000, 'bank_transfer', 'TX-1', $notice->id], [$deposit->type, $deposit->amount_cents, $deposit->balance_cents, $deposit->method, $deposit->reference, $deposit->invoice_id]);
    }

    public function test_a_deposit_is_recorded_once(): void
    {
        $notice = app(AddFunds::class)->handle($this->client, 5000);
        app(RecordInvoicePayment::class)->handle($notice, CarbonImmutable::today(), 'bank_transfer');

        $this->expectException(RuntimeException::class);

        try {
            app(RecordInvoicePayment::class)->handle($notice->fresh(), CarbonImmutable::today(), 'bank_transfer');
        } finally {
            $this->assertSame(5000, $this->client->fresh()->credit_cents);
        }
    }

    public function test_limits(): void
    {
        $add = fn (int $cents) => app(AddFunds::class)->handle($this->client, $cents);
        $fails = function (callable $call, string $message) {
            try {
                $call();
                $this->fail('expected: '.$message);
            } catch (RuntimeException $e) {
                $this->assertStringContainsString($message, $e->getMessage());
            }
        };

        $fails(fn () => $add(999), 'from €10.00 to €300.00');
        $fails(fn () => $add(30001), 'from €10.00 to €300.00');

        $this->addCredit(55000); // 550 of 600: at most 50 more
        $fails(fn () => $add(6000), 'from €10.00 to €50.00');
        $this->assertSame(5000, $add(5000)->total_cents);

        $this->addCredit(4500); // 595: under the minimum left
        $fails(fn () => $add(1000), 'at its maximum');

        config(['gaiacp.credit.enabled' => false]);
        $fails(fn () => $add(1000), 'not available');
    }

    public function test_only_clients_with_an_active_service_can_add_funds(): void
    {
        $this->client->services()->update(['status' => ServiceStatus::Suspended]);

        $this->assertSame('Funds can be added once you have an active service.', ClientCredit::cannotAddFunds($this->client));
    }

    public function test_a_new_add_funds_notice_cancels_the_open_one_and_unpaid_ones_expire(): void
    {
        $first = app(AddFunds::class)->handle($this->client, 2000);
        $second = app(AddFunds::class)->handle($this->client, 3000);

        $this->assertSame(InvoiceStatus::Cancelled, $first->fresh()->status);
        $this->assertSame(InvoiceStatus::Unpaid, $second->fresh()->status);

        $this->travelTo('2026-11-03');
        $this->artisan('gaiacp:send-reminders')->assertSuccessful();

        $this->assertSame(InvoiceStatus::Cancelled, $second->fresh()->status);
    }

    public function test_add_funds_notices_get_no_reminders(): void
    {
        app(AddFunds::class)->handle($this->client, 2000);

        foreach (['2026-10-26', '2026-11-01'] as $day) {
            $this->travelTo($day);
            $this->artisan('gaiacp:send-reminders')->assertSuccessful();
        }

        $this->assertSame([MailTemplate::InvoiceCreated], Mail::sent(ClientMail::class)->map(fn (ClientMail $m) => $m->template())->values()->all());
    }

    public function test_credit_pays_whole_lines_it_covers_and_leaves_the_rest_owed(): void
    {
        $a = $this->service('2026-10-25', 6000);
        $b = $this->service('2026-10-25', 4000);
        $notice = $this->notice($a, $b);
        $this->addCredit(7000);

        $used = app(ApplyCredit::class)->toInvoice($notice);

        $this->assertSame(6000, $used, 'the first line (60) fits, the second (40) no longer does');
        $this->assertSame(1000, $this->client->fresh()->credit_cents);
        $this->assertSame(InvoiceStatus::Unpaid, $notice->fresh()->status);
        $payment = Payment::sole();
        $this->assertSame([ClientCredit::METHOD, 6000, $a->id], [$payment->method, $payment->amount_cents, $payment->service_id]);
        $this->assertSame('2027-10-25', $a->fresh()->next_due_date->toDateString());
        $this->assertSame('2026-10-25', $b->fresh()->next_due_date->toDateString());
        $applied = CreditTransaction::query()->where('type', CreditType::Applied)->sole();
        $this->assertSame([-6000, 1000, $payment->id, $notice->id], [$applied->amount_cents, $applied->balance_cents, $applied->payment_id, $applied->invoice_id]);

        $this->addCredit(3000);
        app(ApplyCredit::class)->toInvoice($notice->fresh());

        $this->assertSame(InvoiceStatus::Paid, $notice->fresh()->status);
        $this->assertSame(0, $this->client->fresh()->credit_cents);
    }

    public function test_paid_funds_pay_the_clients_unpaid_notices(): void
    {
        $notice = $this->notice($this->service('2026-10-25', 6000));
        $deposit = app(AddFunds::class)->handle($this->client, 10000);

        app(RecordInvoicePayment::class)->handle($deposit, CarbonImmutable::today(), 'bank_transfer');

        $this->assertSame(InvoiceStatus::Paid, $notice->fresh()->status);
        $this->assertSame(4000, $this->client->fresh()->credit_cents);
    }

    public function test_the_daily_run_pays_new_notices_from_credit_before_emailing_them(): void
    {
        $this->addCredit(10000);
        $service = $this->service('2026-10-30', 6000);

        $this->artisan('gaiacp:send-reminders')
            ->expectsOutputToContain('1 client(s) paid €60.00 from credit')
            ->assertSuccessful();

        $notice = Invoice::query()->whereHas('items', fn ($q) => $q->where('service_id', $service->id))->sole();
        $this->assertSame(InvoiceStatus::Paid, $notice->status);
        $this->assertNull($notice->emailed_at, 'nothing owed, so no Payment Notice Created');
        $this->assertSame(4000, $this->client->fresh()->credit_cents);
    }

    public function test_nothing_is_paid_automatically_when_auto_apply_is_off(): void
    {
        config(['gaiacp.credit.auto_apply' => false]);
        $this->addCredit(10000);
        $this->service('2026-10-30', 6000);

        $this->artisan('gaiacp:send-reminders')->assertSuccessful();

        $this->assertSame(10000, $this->client->fresh()->credit_cents);
        $this->assertSame(0, Payment::count());
    }

    public function test_reversing_a_payment_made_from_credit_returns_it(): void
    {
        $notice = $this->notice($this->service('2026-10-25', 6000));
        $this->addCredit(6000);
        app(ApplyCredit::class)->toInvoice($notice);

        app(ReversePayment::class)->handle(Payment::sole());

        $this->assertSame(6000, $this->client->fresh()->credit_cents);
        $this->assertSame(InvoiceStatus::Unpaid, $notice->fresh()->status);
        $this->assertSame(CreditType::Refund, CreditTransaction::query()->latest('id')->first()->type);
    }

    public function test_notices_in_another_currency_are_not_paid_from_credit(): void
    {
        $notice = $this->notice($this->service('2026-10-25', 6000));
        $notice->update(['currency' => 'USD']);
        $this->addCredit(10000);

        $this->assertSame(0, app(ApplyCredit::class)->toInvoice($notice->fresh()));
        $this->assertSame(10000, $this->client->fresh()->credit_cents);
    }

    public function test_the_balance_never_goes_below_zero(): void
    {
        $this->addCredit(1000);

        $this->expectException(RuntimeException::class);

        try {
            app(ClientCredit::class)->record($this->client, CreditType::Adjustment, -1500, 'too much');
        } finally {
            $this->assertSame(1000, $this->client->fresh()->credit_cents);
            $this->assertSame(1, CreditTransaction::count());
        }
    }

    public function test_an_add_funds_notice_can_be_paid_by_card(): void
    {
        config(['gaiacp.stripe' => ['mode' => 'test', 'test_secret_key' => 'sk_test_123', 'test_webhook_secret' => 'whsec', 'live_secret_key' => null, 'live_webhook_secret' => null]]);
        $notice = app(AddFunds::class)->handle($this->client, 2500);
        $checkouts = app(StripeCheckouts::class);

        $this->assertTrue($checkouts->canPay($notice));
        $this->assertSame(2500, $checkouts->amountDue($notice));
    }

    private function actAsClient(): void
    {
        $user = User::factory()->create(['role' => UserRole::Client, 'client_id' => $this->client->id, 'email' => $this->client->email]);
        $this->actingAs($user, 'client');
        Filament::setCurrentPanel('client');
    }

    public function test_admin_adds_credit_that_pays_unpaid_notices_and_takes_it_off(): void
    {
        $this->actingAs(User::factory()->create());
        $notice = $this->notice($this->service('2026-10-25', 6000));

        Livewire::test(EditClient::class, ['record' => $this->client->getRouteKey()])
            ->callAction('addCredit', data: ['amount' => '100,00', 'reason' => 'Overpaid by bank transfer', 'apply' => true])
            ->assertHasNoActionErrors()
            ->assertNotified();

        $this->assertSame(InvoiceStatus::Paid, $notice->fresh()->status);
        $this->assertSame(4000, $this->client->fresh()->credit_cents);
        $adjustment = CreditTransaction::query()->where('type', CreditType::Adjustment)->sole();
        $this->assertSame(['Overpaid by bank transfer', $adjustment->user_id], [$adjustment->description, User::query()->where('role', UserRole::Admin)->value('id')]);

        Livewire::test(EditClient::class, ['record' => $this->client->getRouteKey()])
            ->callAction('removeCredit', data: ['amount' => '50', 'reason' => 'too much'])
            ->assertNotified(__('Not saved'));

        Livewire::test(EditClient::class, ['record' => $this->client->getRouteKey()])
            ->callAction('removeCredit', data: ['amount' => '40', 'reason' => 'Refunded'])
            ->assertHasNoActionErrors();

        $this->assertSame(0, $this->client->fresh()->credit_cents);
        $this->get(EditClient::getUrl(['record' => $this->client]))->assertOk()->assertSee(__('Pays payment notices'));
        Livewire::test(CreditRelationManager::class, ['ownerRecord' => $this->client->fresh(), 'pageClass' => EditClient::class])
            ->assertCanSeeTableRecords(CreditTransaction::all())
            ->assertSee('Overpaid by bank transfer');
    }

    public function test_admin_pays_a_notice_with_credit(): void
    {
        config(['gaiacp.credit.auto_apply' => false]);
        $this->actingAs(User::factory()->create());
        $notice = $this->notice($this->service('2026-10-25', 6000));

        Livewire::test(ViewInvoice::class, ['record' => $notice->getRouteKey()])->assertActionHidden('payWithCredit');

        $this->addCredit(6000);

        Livewire::test(ViewInvoice::class, ['record' => $notice->getRouteKey()])
            ->callAction('payWithCredit')
            ->assertNotified('€60.00 paid from credit');

        $this->assertSame(InvoiceStatus::Paid, $notice->fresh()->status);
    }

    public function test_client_adds_funds_from_the_credit_page(): void
    {
        $this->actAsClient();

        $this->get(Credit::getUrl())->assertOk()->assertSee('€0.00');

        Livewire::test(Credit::class)
            ->callAction('addFunds', data: ['amount' => '25'])
            ->assertRedirect();

        $notice = Invoice::query()->where('credit', true)->sole();
        $this->assertSame(2500, $notice->total_cents);
        $this->get(PaymentNoticeResource::getUrl('view', ['record' => $notice]))->assertOk()->assertSee('Add funds');

        Livewire::test(Credit::class)
            ->callAction('addFunds', data: ['amount' => '5'])
            ->assertNotified(__('Not created'));
    }

    public function test_the_credit_page_is_hidden_until_credit_is_on_or_used(): void
    {
        config(['gaiacp.credit.enabled' => false]);
        $this->actAsClient();

        $this->get(Credit::getUrl())->assertForbidden();
        $this->assertArrayNotHasKey('credit', array_filter(Help::features()));

        $this->addCredit(500);
        $this->get(Credit::getUrl())->assertOk()->assertSee('€5.00')->assertDontSee('Add funds');
    }

    public function test_client_pays_a_notice_with_credit_and_sees_it_in_the_guide(): void
    {
        config(['gaiacp.credit.auto_apply' => false]);
        $notice = $this->notice($this->service('2026-10-25', 6000));
        $this->addCredit(8000);
        $this->actAsClient();

        Livewire::test(ViewPaymentNotice::class, ['record' => $notice->getRouteKey()])
            ->callAction('payWithCredit')
            ->assertRedirect();

        $this->assertSame(InvoiceStatus::Paid, $notice->fresh()->status);
        $this->assertSame(2000, $this->client->fresh()->credit_cents);

        $this->get(Help::getUrl())->assertOk()->assertSee('Pay with credit');
    }

    public function test_a_new_orders_notice_is_paid_from_credit_before_it_is_emailed(): void
    {
        $this->addCredit(20000);

        $order = app(PlaceOrder::class)->handle($this->client, [
            ['type' => 'maintenance', 'label' => 'Maintenance', 'price_cents' => 12000, 'billing_cycle' => BillingCycle::Annually],
        ]);

        $notice = Invoice::query()->where('order_id', $order->id)->sole();
        $this->assertSame(InvoiceStatus::Paid, $notice->status);
        $this->assertSame(8000, $this->client->fresh()->credit_cents);
        $this->assertSame(ClientCredit::METHOD, Payment::sole()->method);
        $this->assertNull($notice->emailed_at);
    }
}
