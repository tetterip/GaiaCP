<?php

namespace Tests\Feature;

use App\Actions\PlaceOrder;
use App\Actions\RecordInvoicePayment;
use App\Actions\RecordOrderPayment;
use App\Actions\RecordServicePayment;
use App\Actions\ReversePayment;
use App\Api\OpenApi;
use App\Enums\BillingCycle;
use App\Enums\InvoiceStatus;
use App\Enums\ServiceStatus;
use App\Filament\Resources\Services\Pages\EditService;
use App\Models\ApiKey;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Service;
use App\Models\TaxInvoice;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A payment typed in by hand is checked against its payment notice (App\Actions\RecordServicePayment): the order's and
 * the service's Record payment ask for the notice's amount with tax, and a different amount says what the difference
 * is. A deleted service takes its lines off its notices.
 */
class PaymentAmountTest extends TestCase
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
            'gaiacp.credit.auto_apply' => true,
        ]);
        $this->client = Client::factory()->create();
    }

    /** Two items: 100 € and 50 €, so the notice asks 124 € + 62 € = 186 € with 24% VAT. */
    private function order(): Order
    {
        return app(PlaceOrder::class)->handle($this->client, [
            ['type' => 'other', 'label' => 'A', 'price_cents' => 10000, 'billing_cycle' => BillingCycle::Annually],
            ['type' => 'other', 'label' => 'B', 'price_cents' => 5000, 'billing_cycle' => BillingCycle::Annually],
        ]);
    }

    private function first(Order $order): Service
    {
        return $order->services()->orderBy('id')->firstOrFail();
    }

    private function pay(Service $service, int $cents, ?string $difference = null): RecordServicePayment
    {
        $recorder = app(RecordServicePayment::class);
        $recorder->handle($service->fresh(), $cents, CarbonImmutable::today(), 'bank_transfer', 'TX-1', difference: $difference);

        return $recorder;
    }

    public function test_the_order_payment_records_what_its_notice_asks_with_tax(): void
    {
        $order = $this->order();

        app(RecordOrderPayment::class)->handle($order, CarbonImmutable::today(), 'bank_transfer');

        $notice = Invoice::sole();
        $this->assertSame(18600, $notice->total_cents);
        $this->assertSame(InvoiceStatus::Paid, $notice->status);
        $this->assertSame(18600, (int) Payment::sum('amount_cents'), 'the payments add up to the notice');
        $this->assertSame(18600, TaxInvoice::sole()->total_cents);
    }

    public function test_the_service_payment_suggests_the_notice_amount(): void
    {
        $service = $this->first($this->order());

        $this->assertSame(12400, RecordServicePayment::expected($service));
        $this->assertSame(12400, RecordServicePayment::suggested($service));
        $this->assertSame([], RecordServicePayment::options($service, 12400), 'the same amount: nothing to choose');

        // No notice yet (paid ahead): what a notice would ask, with tax
        $ahead = Service::factory()->create(['client_id' => $this->client->id, 'price_cents' => 6000, 'next_due_date' => '2027-10-01']);
        $this->assertNull(RecordServicePayment::expected($ahead));
        $this->assertSame(7440, RecordServicePayment::suggested($ahead));
    }

    public function test_less_into_the_balance_keeps_the_cycle_owed_until_the_rest_comes(): void
    {
        $service = $this->first($this->order());
        $due = $service->next_due_date->toDateString();

        $first = $this->pay($service, 10000, RecordServicePayment::CREDIT);

        $this->assertNull($first->payment);
        $this->assertSame(10000, $first->credited);
        $this->assertSame(10000, (int) $this->client->refresh()->credit_cents);
        $this->assertSame($due, $service->fresh()->next_due_date->toDateString(), 'still owed');
        $this->assertSame(0, Payment::count());

        // The rest: the balance now covers the line, which it pays
        $rest = $this->pay($service, 2400, RecordServicePayment::CREDIT);

        $this->assertNotNull($rest->payment);
        $this->assertSame(12400, $rest->payment->amount_cents);
        $this->assertSame('credit', $rest->payment->method);
        $this->assertSame(0, (int) $this->client->refresh()->credit_cents);
        $this->assertNotSame($due, $service->fresh()->next_due_date->toDateString(), 'paid once');
        $this->assertSame(1, Payment::count());
    }

    public function test_less_as_a_discount_pays_the_cycle_and_the_invoice_comes_to_what_was_paid(): void
    {
        $order = $this->order();
        [$a, $b] = $order->services()->orderBy('id')->get()->all();

        $this->pay($a, 10000, RecordServicePayment::DISCOUNT);
        $this->pay($b, 6200);

        $notice = Invoice::sole();
        $discount = $notice->items()->where('fee', InvoiceItem::DISCOUNT)->sole();
        $this->assertSame(-2400, $discount->total_cents);
        $this->assertSame(-1935, $discount->amount_cents, 'net of tax');
        $this->assertSame(-465, $discount->tax_cents);
        $this->assertSame(16200, $notice->total_cents);
        $this->assertSame(InvoiceStatus::Paid, $notice->status);
        $this->assertSame(16200, (int) Payment::sum('amount_cents'));
        $this->assertSame(16200, TaxInvoice::sole()->total_cents);
        $this->assertSame(10000, $a->fresh()->price_cents, 'the price stays as it is');

        // Reversed: the discount comes off and the notice asks for the whole cycle again
        app(ReversePayment::class)->handle(Payment::query()->where('service_id', $a->id)->sole());

        $notice->refresh();
        $this->assertSame(0, $notice->items()->where('fee', InvoiceItem::DISCOUNT)->count());
        $this->assertSame(18600, $notice->total_cents);
        $this->assertSame(InvoiceStatus::Unpaid, $notice->status);
        $this->assertSame(12400, RecordServicePayment::expected($a->fresh()));
    }

    public function test_more_pays_the_cycle_and_the_rest_goes_into_the_balance(): void
    {
        $service = $this->first($this->order());

        $recorder = $this->pay($service, 15000, RecordServicePayment::CREDIT);

        $this->assertSame(12400, $recorder->payment->amount_cents);
        $this->assertSame(2600, $recorder->credited);
        $this->assertSame(2600, (int) $this->client->refresh()->credit_cents);

        // Reversed by mistake: the rest comes out of the balance again
        app(ReversePayment::class)->handle($recorder->payment);
        $this->assertSame(0, (int) $this->client->refresh()->credit_cents);
    }

    public function test_more_can_be_kept_as_one_payment(): void
    {
        $service = $this->first($this->order());

        $this->assertSame(15000, $this->pay($service, 15000, RecordServicePayment::KEEP)->payment->amount_cents);
        $this->assertSame(0, (int) $this->client->refresh()->credit_cents);
    }

    public function test_a_choice_that_does_not_fit_the_difference_is_refused(): void
    {
        $service = $this->first($this->order());

        $this->expectException(InvalidArgumentException::class);
        $this->pay($service, 10000, RecordServicePayment::KEEP);
    }

    public function test_without_credit_less_is_a_discount_and_more_is_kept(): void
    {
        config(['gaiacp.credit.enabled' => false]);
        $service = $this->first($this->order());

        $this->assertSame([RecordServicePayment::DISCOUNT], array_keys(RecordServicePayment::options($service, 10000)));
        $this->assertSame([RecordServicePayment::KEEP], array_keys(RecordServicePayment::options($service, 15000)));
    }

    public function test_the_record_payment_form_asks_about_the_difference(): void
    {
        $this->actingAs(User::factory()->create());
        $service = $this->first($this->order());
        $service->update(['status' => ServiceStatus::Active]);

        Livewire::test(EditService::class, ['record' => $service->id])
            ->mountAction('recordPayment')
            ->assertSchemaStateSet(['amount' => '124.00'], 'mountedActionSchema0')
            ->setActionData(['amount' => '100'])
            ->assertSchemaStateSet(['difference' => RecordServicePayment::CREDIT], 'mountedActionSchema0')
            ->setActionData(['difference' => RecordServicePayment::DISCOUNT, 'paid_on' => '2026-10-09', 'method' => 'cash'])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertSame(10000, Payment::sole()->amount_cents);
        $this->assertSame(1, InvoiceItem::query()->where('fee', InvoiceItem::DISCOUNT)->count());
    }

    public function test_the_api_asks_for_difference_when_the_amount_differs(): void
    {
        $service = $this->first($this->order());
        $service->update(['status' => ServiceStatus::Active]);
        [, $token] = ApiKey::generate(['name' => 'Bank', 'user_id' => User::factory()->create()->id, 'permissions' => OpenApi::permissions()]);
        $api = fn () => $this->flushHeaders()->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json']);

        $api()->postJson('/api/v1/payments', ['service_id' => $service->id, 'amount_cents' => 10000, 'method' => 'cash'])
            ->assertUnprocessable()->assertJsonValidationErrors('difference');
        $this->assertSame(0, Payment::count());

        $api()->postJson('/api/v1/payments', ['service_id' => $service->id, 'amount_cents' => 10000, 'method' => 'cash', 'difference' => 'credit'])
            ->assertCreated()->assertJson(['data' => null, 'credited_cents' => 10000]);

        // Without an amount: the rest of the notice is not what it asks, the whole cycle is
        $api()->postJson('/api/v1/payments', ['service_id' => $service->id, 'method' => 'cash'])
            ->assertCreated()->assertJsonPath('data.amount_cents', 12400);
    }

    public function test_a_deleted_service_comes_off_its_notice(): void
    {
        $order = $this->order();
        [$a, $b] = $order->services()->orderBy('id')->get()->all();

        $b->delete();

        $notice = Invoice::sole();
        $this->assertSame(1, $notice->items()->count());
        $this->assertSame(12400, $notice->total_cents);

        app(RecordInvoicePayment::class)->handle($notice, CarbonImmutable::today(), 'bank_transfer');
        $this->assertSame(12400, TaxInvoice::sole()->total_cents, 'invoiced for what was paid');

        // The only line of a notice: the notice is cancelled
        $other = $this->order();
        $other->services->each->delete();
        $this->assertSame(InvoiceStatus::Cancelled, $other->invoices()->sole()->status);
    }

    public function test_the_migration_takes_old_lines_of_deleted_services_off(): void
    {
        $order = $this->order();
        $notice = Invoice::sole();
        [$a, $b] = $order->services()->orderBy('id')->get()->all();
        app(RecordServicePayment::class)->handle($a, 12400, CarbonImmutable::today(), 'cash');

        // As a deletion left it before: the line stays, naming no service
        $notice->items()->where('service_id', $b->id)->update(['service_id' => null]);
        Service::withoutEvents(fn () => $b->delete());

        (require database_path('migrations/2026_11_07_000200_remove_deleted_services_from_notices.php'))->up();

        $notice->refresh();
        $this->assertSame(1, $notice->items()->count());
        $this->assertSame(12400, $notice->total_cents);
        $this->assertSame(InvoiceStatus::Paid, $notice->status);
        $this->assertSame(1, DB::table('invoices')->count());
    }
}
