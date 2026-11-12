<?php

namespace Tests\Feature;

use App\Actions\CancelOrder;
use App\Actions\PlaceOrder;
use App\Actions\RecordOrderPayment;
use App\Api\OpenApi;
use App\Credit\ClientCredit;
use App\Enums\BillingCycle;
use App\Enums\CreditType;
use App\Enums\InvoiceStatus;
use App\Enums\OrderStatus;
use App\Enums\ServiceStatus;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Models\ApiKey;
use App\Models\Client;
use App\Models\CreditTransaction;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use App\Models\TaxInvoice;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/** Cancel order on an order whose items were paid for but not set up yet (App\Actions\CancelOrder). */
class CancelPaidOrderTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config(['gaiacp.credit.enabled' => true, 'gaiacp.tax.enabled' => false]);
        $this->travelTo('2026-10-09 10:00');
        $this->client = Client::factory()->create();
    }

    private function paidOrder(string $method = 'bank_transfer'): Order
    {
        $order = app(PlaceOrder::class)->handle($this->client, [
            ['type' => 'maintenance', 'label' => 'Maintenance', 'price_cents' => 12000, 'billing_cycle' => BillingCycle::Annually],
            ['type' => 'other', 'label' => 'Setup', 'price_cents' => 5000, 'first_payment_cents' => 7000, 'billing_cycle' => BillingCycle::Annually],
        ]);

        app(RecordOrderPayment::class)->handle($order, CarbonImmutable::parse('2026-10-09'), $method);

        $this->assertSame(InvoiceStatus::Paid, Invoice::sole()->status);
        $this->assertTrue(CancelOrder::canCancel($order->refresh()), 'paid but still pending');

        return $order;
    }

    public function test_returning_the_payments_to_credit(): void
    {
        $order = $this->paidOrder();
        $dueBefore = $order->services()->pluck('next_due_date', 'id');

        app(CancelOrder::class)->handle($order, payments: CancelOrder::REFUND_TO_CREDIT);

        $this->assertSame(OrderStatus::Cancelled, $order->refresh()->status);
        $this->assertTrue($order->services()->get()->every(fn ($s) => $s->status === ServiceStatus::Cancelled));
        $this->assertSame(0, (int) Payment::query()->sum('amount_cents'), 'each payment has its refund');
        $this->assertSame(InvoiceStatus::Cancelled, Invoice::sole()->status);
        $this->assertSame(12000 + 7000, $this->client->refresh()->credit_cents);
        $this->assertSame(2, CreditTransaction::query()->where('type', CreditType::Refund)->count());
        $this->assertNotEquals($dueBefore->all(), $order->services()->pluck('next_due_date', 'id')->all(), 'due dates go back');
        $this->assertDatabaseHas('activity_logs', ['event' => 'order.cancelled', 'description' => "Order #{$order->id} cancelled; payments returned to credit"]);
    }

    public function test_the_payments_stay_in_their_month_and_the_refunds_go_in_the_month_of_the_cancel(): void
    {
        $order = $this->paidOrder();
        $this->travelTo('2026-11-03 10:00');

        app(CancelOrder::class)->handle($order, payments: CancelOrder::REFUNDED_ELSEWHERE);

        $payments = Payment::query()->whereNull('refund_of')->get();
        $this->assertCount(2, $payments);
        $this->assertTrue($payments->every(fn (Payment $p) => $p->paid_on->toDateString() === '2026-10-09'), 'payments keep their day');
        $this->assertSame(12000 + 7000, (int) Payment::query()->whereBetween('paid_on', ['2026-10-01', '2026-10-31'])->sum('amount_cents'), 'October income unchanged');
        $this->assertSame(-(12000 + 7000), (int) Payment::query()->whereBetween('paid_on', ['2026-11-01', '2026-11-30'])->sum('amount_cents'));
        $this->assertTrue($payments->every(fn (Payment $p) => $p->refunds()->sole()->method === 'bank_transfer'));
        $this->assertFalse(CancelOrder::hasPayments($order->refresh()));
    }

    public function test_refunded_elsewhere_undoes_the_payments_without_credit(): void
    {
        $order = $this->paidOrder();

        app(CancelOrder::class)->handle($order, payments: CancelOrder::REFUNDED_ELSEWHERE);

        $this->assertSame(0, (int) Payment::query()->sum('amount_cents'), 'each payment has its refund');
        $this->assertSame(InvoiceStatus::Cancelled, Invoice::sole()->status);
        $this->assertSame(0, (int) $this->client->refresh()->credit_cents);
    }

    public function test_a_payment_made_from_credit_always_goes_back_to_credit(): void
    {
        $order = $this->paidOrder(ClientCredit::METHOD);

        app(CancelOrder::class)->handle($order, payments: CancelOrder::REFUNDED_ELSEWHERE);

        $this->assertSame(12000 + 7000, $this->client->refresh()->credit_cents);
    }

    public function test_keeping_the_payments_leaves_them_as_they_were(): void
    {
        $order = $this->paidOrder();

        app(CancelOrder::class)->handle($order, payments: CancelOrder::KEEP);

        $this->assertSame(OrderStatus::Cancelled, $order->refresh()->status);
        $this->assertSame(2, Payment::count());
        $this->assertSame(InvoiceStatus::Paid, Invoice::sole()->status);
        $this->assertSame(0, (int) $this->client->refresh()->credit_cents);
    }

    public function test_an_issued_invoice_gets_a_credit_note(): void
    {
        config(['gaiacp.invoicing.enabled' => true]);
        $order = $this->paidOrder();
        $invoice = TaxInvoice::query()->sole();

        app(CancelOrder::class)->handle($order, payments: CancelOrder::REFUND_TO_CREDIT);

        $this->assertTrue($invoice->refresh()->fullyCredited());
        $this->assertSame(1, TaxInvoice::query()->where('type', TaxInvoice::CREDIT_NOTE)->count());
    }

    public function test_the_default_is_credit_when_credit_is_on_else_refunded(): void
    {
        $this->assertSame(CancelOrder::REFUND_TO_CREDIT, CancelOrder::defaultPaymentOption());

        config(['gaiacp.credit.enabled' => false]);
        $order = $this->paidOrder();
        app(CancelOrder::class)->handle($order);

        $this->assertSame(0, (int) Payment::query()->sum('amount_cents'), 'each payment has its refund');
        $this->assertSame(0, (int) $this->client->refresh()->credit_cents);
    }

    public function test_the_order_page_asks_what_happens_to_the_money(): void
    {
        $this->actingAs(User::factory()->create());
        $order = $this->paidOrder();

        Livewire::test(ViewOrder::class, ['record' => $order->id])
            ->mountAction('cancelOrder')
            ->assertActionDataSet(['payments' => CancelOrder::REFUND_TO_CREDIT])
            ->setActionData(['payments' => CancelOrder::KEEP])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertSame(OrderStatus::Cancelled, $order->refresh()->status);
        $this->assertSame(2, Payment::count());
    }

    public function test_the_api_takes_the_payments_option(): void
    {
        $order = $this->paidOrder();
        [, $token] = ApiKey::generate(['name' => 'Test', 'user_id' => auth()->id() ?? User::factory()->create()->id, 'permissions' => OpenApi::permissions()]);

        $this->withToken($token)->postJson("/api/v1/orders/{$order->id}/cancel", ['payments' => 'nonsense'])->assertUnprocessable();
        $this->withToken($token)->postJson("/api/v1/orders/{$order->id}/cancel", ['payments' => 'refunded'])->assertOk();

        $this->assertSame(0, (int) Payment::query()->sum('amount_cents'), 'each payment has its refund');
        $this->assertSame(0, (int) $this->client->refresh()->credit_cents);
    }
}
