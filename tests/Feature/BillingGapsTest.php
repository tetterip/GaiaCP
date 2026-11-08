<?php

namespace Tests\Feature;

use App\Actions\PlaceOrder;
use App\Actions\RecordPayment;
use App\Actions\RefundPayment;
use App\Actions\ReversePayment;
use App\Api\OpenApi;
use App\Enums\BillingCycle;
use App\Enums\InvoiceStatus;
use App\Enums\ServiceStatus;
use App\Filament\Resources\Clients\Pages\EditClient;
use App\Filament\Resources\Clients\Widgets\ClientSummary;
use App\Filament\Resources\Services\Pages\EditService;
use App\Filament\Resources\Services\RelationManagers\PaymentsRelationManager;
use App\Filament\Widgets\BillingOverview;
use App\Invoices\GenerateInvoices;
use App\Models\ApiKey;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Service;
use App\Models\TaxInvoice;
use App\Models\User;
use App\Reports\IncomeReport;
use Carbon\CarbonImmutable;
use Filament\Actions\DeleteAction;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/** What happens to the money when a due date, a notice, a payment or a client changes after billing started. */
class BillingGapsTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->travelTo('2026-10-09 10:00');
        config([
            'gaiacp.tax' => ['enabled' => true, 'inclusive' => false, 'rules' => [['name' => 'VAT', 'rate' => 24, 'country' => null]]],
            'gaiacp.invoicing.enabled' => true,
            'gaiacp.credit.enabled' => true,
        ]);
        $this->client = Client::factory()->create();
        $this->actingAs(User::factory()->create());
    }

    private function order(): Order
    {
        return app(PlaceOrder::class)->handle($this->client, [
            ['type' => 'other', 'label' => 'A', 'price_cents' => 10000, 'billing_cycle' => BillingCycle::Annually],
            ['type' => 'other', 'label' => 'B', 'price_cents' => 5000, 'billing_cycle' => BillingCycle::Annually],
        ]);
    }

    private function pay(Service $service, int $cents): Payment
    {
        $service = $service->fresh();

        return app(RecordPayment::class)->handle($service, $cents, CarbonImmutable::today(), 'bank_transfer', forDueDate: $service->next_due_date);
    }

    public function test_a_due_date_moved_by_hand_takes_its_line_off_the_old_notice(): void
    {
        $service = Service::factory()->create(['client_id' => $this->client->id, 'type' => 'other', 'server_id' => null, 'price_cents' => 6000, 'start_date' => '2025-10-15', 'next_due_date' => '2026-10-15']);
        app(GenerateInvoices::class)->handle(CarbonImmutable::today());
        $old = Invoice::sole();

        Livewire::test(EditService::class, ['record' => $service->id])
            ->fillForm(['next_due_date' => '2026-10-20'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(InvoiceStatus::Cancelled, $old->refresh()->status, 'its only line came off');

        app(GenerateInvoices::class)->handle(CarbonImmutable::today());
        $this->assertSame(1, Invoice::unpaid()->count(), 'one notice, for the new date');
        $this->assertSame('2026-10-20', Invoice::unpaid()->sole()->items()->sole()->due_date->toDateString());
    }

    public function test_cancelling_a_partly_paid_notice_keeps_the_paid_part_invoiced(): void
    {
        $order = $this->order();
        [$a] = $order->services()->orderBy('id')->get()->all();
        $this->pay($a, 12400);
        $notice = Invoice::sole();

        $this->assertFalse($notice->cancel());

        $notice->refresh();
        $this->assertSame(InvoiceStatus::Paid, $notice->status);
        $this->assertSame(12400, $notice->total_cents);
        $this->assertSame(12400, TaxInvoice::sole()->total_cents);

        // Nothing paid: cancelled as before
        $other = $this->order()->invoices()->sole();
        $this->assertTrue($other->cancel());
        $this->assertSame(InvoiceStatus::Cancelled, $other->refresh()->status);
    }

    public function test_a_partly_paid_notice_counts_only_what_is_still_owed(): void
    {
        $order = $this->order();
        [$a] = $order->services()->orderBy('id')->get()->all();
        $this->pay($a, 12400);

        $this->assertSame(18600, Invoice::sole()->total_cents, 'the notice stays whole');
        $this->assertSame(['EUR' => 6200], Invoice::owedByCurrency(Invoice::query()->unpaid())->all());
        $this->assertSame(6200, Invoice::owedInBase(Invoice::query()->unpaid()));

        Livewire::test(ClientSummary::class, ['record' => $this->client])->assertSee('€62.00')->assertDontSee('€186.00');
        Livewire::test(BillingOverview::class)->assertSee('€62.00')->assertDontSee('€186.00');
    }

    public function test_reversing_the_payment_of_a_one_time_service_makes_it_owed_again(): void
    {
        $service = Service::factory()->create(['client_id' => $this->client->id, 'type' => 'other', 'billing_cycle' => BillingCycle::OneTime,
            'status' => ServiceStatus::Active, 'price_cents' => 1000, 'start_date' => '2026-10-09', 'next_due_date' => '2026-10-09']);
        $payment = $this->pay($service, 1000);
        $this->assertSame(ServiceStatus::Completed, $service->refresh()->status);

        app(ReversePayment::class)->handle($payment);

        $this->assertSame(ServiceStatus::Active, $service->refresh()->status);
        $this->assertTrue(Service::billable()->whereKey($service->id)->exists());
    }

    public function test_refunding_an_older_payment(): void
    {
        $service = Service::factory()->create(['client_id' => $this->client->id, 'type' => 'other', 'server_id' => null, 'price_cents' => 10000, 'start_date' => '2025-10-15', 'next_due_date' => '2026-10-15']);
        app(GenerateInvoices::class)->handle(CarbonImmutable::today());
        $first = $this->pay($service, 12400);
        $this->travelTo('2027-10-01');
        $this->pay($service, 12400); // a later payment: the first can no longer be reversed
        $due = $service->refresh()->next_due_date->toDateString();
        $this->assertFalse(ReversePayment::canReverse($first));
        $this->assertTrue(RefundPayment::canRefund($first));

        $refund = app(RefundPayment::class)->handle($first, RefundPayment::ELSEWHERE, CarbonImmutable::parse('2027-10-01'));

        $this->assertSame(-12400, $refund->amount_cents);
        $this->assertSame($first->id, $refund->refund_of);
        $this->assertSame($due, $service->refresh()->next_due_date->toDateString(), 'the due date stays');
        $this->assertFalse(RefundPayment::canRefund($first->fresh()), 'once');
        $this->assertFalse(RefundPayment::canRefund($refund));
        $this->assertFalse(ReversePayment::canReverse($refund));
        $this->assertSame(1, TaxInvoice::query()->where('type', TaxInvoice::CREDIT_NOTE)->count(), 'a credit note for its invoice');
        $this->assertSame(-12400, (int) IncomeReport::paidBetween(Payment::query(), CarbonImmutable::parse('2027-10-01'), CarbonImmutable::parse('2027-10-01'))
            ->where('refund_of', '!=', null)->sum('amount_cents'), 'counted on the day it was given back');
    }

    public function test_a_refund_to_credit_and_from_the_payments_tab(): void
    {
        $service = Service::factory()->create(['client_id' => $this->client->id, 'type' => 'other', 'server_id' => null, 'price_cents' => 5000, 'start_date' => '2025-10-15', 'next_due_date' => '2026-10-15']);
        $payment = $this->pay($service, 5000);

        Livewire::test(PaymentsRelationManager::class, ['ownerRecord' => $service->fresh(), 'pageClass' => EditService::class])
            ->callAction(TestAction::make('refund')->table($payment), data: ['to' => RefundPayment::TO_CREDIT, 'on' => '2026-10-09'])
            ->assertHasNoActionErrors();

        $this->assertSame(5000, (int) $this->client->refresh()->credit_cents);
        $this->assertSame(1, Payment::query()->whereNotNull('refund_of')->count());
    }

    public function test_the_api_refunds_a_payment(): void
    {
        $service = Service::factory()->create(['client_id' => $this->client->id, 'type' => 'other', 'server_id' => null, 'price_cents' => 5000, 'start_date' => '2025-10-15', 'next_due_date' => '2026-10-15']);
        $payment = $this->pay($service, 5000);
        [, $token] = ApiKey::generate(['name' => 'Bank', 'user_id' => auth()->id(), 'permissions' => OpenApi::permissions()]);
        $api = fn () => $this->flushHeaders()->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json']);

        $api()->postJson("/api/v1/payments/{$payment->id}/refund", ['to' => 'elsewhere'])
            ->assertCreated()->assertJsonPath('data.amount_cents', -5000)->assertJsonPath('data.refund_of', $payment->id);
        $api()->postJson("/api/v1/payments/{$payment->id}/refund", ['to' => 'elsewhere'])->assertConflict();
    }

    public function test_a_refund_through_the_api_needs_the_payments_delete_permission(): void
    {
        $service = Service::factory()->create(['client_id' => $this->client->id, 'type' => 'other', 'server_id' => null, 'price_cents' => 5000, 'start_date' => '2025-10-15', 'next_due_date' => '2026-10-15']);
        $payment = $this->pay($service, 5000);
        [, $token] = ApiKey::generate(['name' => 'Bank', 'user_id' => auth()->id(), 'permissions' => ['payments.read', 'payments.create']]);

        $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->postJson("/api/v1/payments/{$payment->id}/refund", ['to' => 'elsewhere'])->assertForbidden();
        $this->assertSame(0, Payment::query()->whereNotNull('refund_of')->count());
    }

    public function test_a_client_with_only_orders_or_notices_keeps_them(): void
    {
        $order = $this->order();
        Service::withoutEvents(fn () => $order->services()->get()->each->delete());

        $this->assertTrue($this->client->keepsHistory());
        Livewire::test(EditClient::class, ['record' => $this->client->id])->assertActionHidden(DeleteAction::class);

        $this->assertFalse(Client::factory()->create()->keepsHistory());
    }
}
