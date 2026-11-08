<?php

namespace Tests\Feature;

use App\Actions\CancelOrder;
use App\Actions\PlaceOrder;
use App\Actions\RecordOrderPayment;
use App\Enums\BillingCycle;
use App\Enums\ClientMessageKind;
use App\Enums\OrderStatus;
use App\Enums\ServiceStatus;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Models\Client;
use App\Models\ClientMessage;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/** Orders list → pick some → Complete / Cancel / Send message / Delete. */
class OrderBulkActionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        config(['gaiacp.tax.enabled' => false]);
        $this->actingAs(User::factory()->create());
    }

    private function order(?Client $client = null): Order
    {
        return app(PlaceOrder::class)->handle($client ?? Client::factory()->create(), [
            ['type' => 'other', 'label' => 'Setup', 'price_cents' => 5000, 'billing_cycle' => BillingCycle::Annually],
        ]);
    }

    public function test_complete_sets_up_the_pending_ones(): void
    {
        $pending = $this->order();
        $cancelled = $this->order();
        app(CancelOrder::class)->handle($cancelled);

        Livewire::test(ListOrders::class)
            ->callTableBulkAction('complete', [$pending, $cancelled])
            ->assertNotified('1 order completed');

        $this->assertSame(OrderStatus::Completed, $pending->fresh()->status);
        $this->assertSame(ServiceStatus::Active, $pending->services()->first()->status);
        $this->assertSame(OrderStatus::Cancelled, $cancelled->fresh()->status);
    }

    public function test_cancel_asks_once_what_happens_to_payments(): void
    {
        $paid = $this->order();
        $unpaid = $this->order();
        app(RecordOrderPayment::class)->handle($paid, CarbonImmutable::today(), 'bank_transfer');

        Livewire::test(ListOrders::class)
            ->callTableBulkAction('cancel', [$paid, $unpaid], ['payments' => CancelOrder::REFUNDED_ELSEWHERE])
            ->assertNotified('2 orders cancelled');

        $this->assertSame(OrderStatus::Cancelled, $paid->fresh()->status);
        $this->assertSame(OrderStatus::Cancelled, $unpaid->fresh()->status);
        $this->assertSame(0, $paid->services()->first()->payments()->count());
    }

    public function test_delete_removes_only_cancelled_orders_nothing_was_paid_for(): void
    {
        $cancelled = $this->order();
        app(CancelOrder::class)->handle($cancelled);
        $kept = $this->order();
        app(RecordOrderPayment::class)->handle($kept, CarbonImmutable::today(), 'bank_transfer');
        app(CancelOrder::class)->handle($kept, payments: CancelOrder::KEEP);
        $pending = $this->order();

        Livewire::test(ListOrders::class)
            ->callTableBulkAction('delete', [$cancelled, $kept, $pending])
            ->assertNotified('1 order deleted');

        $this->assertNull(Order::query()->find($cancelled->id));
        $this->assertSame(0, Service::query()->where('order_id', $cancelled->id)->count());
        $this->assertSame(0, Invoice::query()->where('order_id', $cancelled->id)->count());
        $this->assertNotNull($kept->fresh());
        $this->assertNotNull($pending->fresh());
    }

    public function test_send_message_goes_once_to_each_client(): void
    {
        $client = Client::factory()->create(['informational_emails' => false]);
        $first = $this->order($client);
        $second = $this->order($client);
        $other = $this->order();

        Livewire::test(ListOrders::class)
            ->callTableBulkAction('sendMessage', [$first, $second, $other], [
                'kind' => ClientMessageKind::Service->value, 'subject' => 'Maintenance tonight', 'body' => 'Hello {$client_first_name}',
            ])
            ->assertNotified('Sending to 2 clients');

        $message = ClientMessage::query()->sole();
        $this->assertEqualsCanonicalizing([$client->id, $other->client_id], $message->recipients()->pluck('client_id')->all());
        $this->assertSame('Maintenance tonight', $message->subject());
    }

    public function test_the_list_shows_each_orders_payment_status(): void
    {
        $paid = $this->order();
        app(RecordOrderPayment::class)->handle($paid, CarbonImmutable::today(), 'bank_transfer');
        $unpaid = $this->order();

        Livewire::test(ListOrders::class)
            ->assertTableColumnStateSet('payment', 'Paid', $paid)
            ->assertTableColumnStateSet('payment', 'Unpaid', $unpaid);
    }
}
