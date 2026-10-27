<?php

namespace Tests\Feature;

use App\Actions\PlaceOrder;
use App\Enums\BillingCycle;
use App\Enums\InvoiceStatus;
use App\Enums\OrderStatus;
use App\Enums\ServiceStatus;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Server;
use App\Support\Health\SchedulerMonitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** gaiacp:cancel-unpaid-orders: Settings → Automation → Cancel unpaid orders after (App\Actions\CancelOrder). */
class CancelUnpaidOrdersTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = Client::factory()->create();
        config(['gaiacp.automation.cancel_unpaid_orders_days' => 7]);
    }

    private function place(string $on): Order
    {
        $this->travelTo($on.' 10:00');

        return app(PlaceOrder::class)->handle($this->client, [
            ['type' => 'hosting', 'label' => 'Hosting Basic', 'domain' => 'acme.gr', 'server_id' => Server::factory()->create()->id, 'package' => 'basic',
                'price_cents' => 6000, 'billing_cycle' => BillingCycle::Annually],
        ]);
    }

    public function test_an_order_unpaid_for_the_set_days_is_cancelled_with_its_items_and_notice(): void
    {
        $old = $this->place('2026-10-01');
        $recent = $this->place('2026-10-05');
        $this->assertSame(InvoiceStatus::Unpaid, $old->invoices()->sole()->status);

        $this->travelTo('2026-10-08 09:00');
        $this->artisan('gaiacp:cancel-unpaid-orders')->assertSuccessful();

        $this->assertSame(OrderStatus::Cancelled, $old->refresh()->status);
        $this->assertSame(ServiceStatus::Cancelled, $old->services()->sole()->status);
        $this->assertSame(InvoiceStatus::Cancelled, $old->invoices()->sole()->status);
        $this->assertSame(1, ActivityLog::query()->where('event', 'order.auto_cancelled')->count());

        $this->assertSame(OrderStatus::Pending, $recent->refresh()->status, 'only 3 days old');

        // Safe to re-run
        $this->artisan('gaiacp:cancel-unpaid-orders')->assertSuccessful();
        $this->assertSame(1, ActivityLog::query()->where('event', 'order.auto_cancelled')->count());
    }

    public function test_orders_with_a_payment_or_an_item_set_up_are_left_to_the_admin(): void
    {
        $paid = $this->place('2026-09-01');
        Payment::factory()->for($paid->services()->sole())->create();
        $started = $this->place('2026-09-01');
        $started->services()->update(['status' => ServiceStatus::Active]);

        $this->travelTo('2026-10-08');
        $this->artisan('gaiacp:cancel-unpaid-orders')->assertSuccessful();

        $this->assertSame(OrderStatus::Pending, $paid->refresh()->status);
        $this->assertSame(OrderStatus::Pending, $started->refresh()->status);
    }

    public function test_off_when_empty_and_the_dry_run_changes_nothing(): void
    {
        $order = $this->place('2026-09-01');
        $this->travelTo('2026-10-08');

        $this->artisan('gaiacp:cancel-unpaid-orders --dry-run')
            ->expectsOutputToContain("would cancel order #{$order->id}")
            ->assertSuccessful();
        $this->assertSame(OrderStatus::Pending, $order->refresh()->status);

        config(['gaiacp.automation.cancel_unpaid_orders_days' => null]);
        $this->artisan('gaiacp:cancel-unpaid-orders')->assertSuccessful();
        $this->assertSame(OrderStatus::Pending, $order->refresh()->status);
    }

    public function test_it_is_a_daily_command(): void
    {
        $this->assertContains('gaiacp:cancel-unpaid-orders', SchedulerMonitor::DAILY_COMMANDS);
    }
}
