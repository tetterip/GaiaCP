<?php

namespace Tests\Feature;

use App\Actions\SetUpService;
use App\Actions\Tickets;
use App\Enums\AdminArea;
use App\Enums\OrderStatus;
use App\Enums\ServiceStatus;
use App\Enums\UserRole;
use App\Filament\Resources\Tickets\TicketResource;
use App\Filament\Widgets\NeedsAttention;
use App\Filament\Widgets\RecentActivity;
use App\Filament\Widgets\ServerCapacity;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\ModuleQueueItem;
use App\Models\Order;
use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use App\Provisioning\ProvisioningException;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\FakesWhm;
use Tests\TestCase;

/** The dashboard's second round: an action on a Needs attention row, Recent activity, and the Servers card's warnings. */
class DashboardActionsTest extends TestCase
{
    use FakesWhm, RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->fakeWhm();
        $this->travelTo(CarbonImmutable::parse('2026-11-19 10:00'));
        $this->admin = User::factory()->create();
        $this->actingAs($this->admin);
    }

    /** @return array<string, mixed>|null the row with this icon */
    private function row(string $icon): ?array
    {
        return collect(NeedsAttention::items())->firstWhere('icon', $icon);
    }

    private function failedSetUp(): Service
    {
        $service = Service::factory()->create(['status' => ServiceStatus::Pending, 'domain' => 'acme.gr', 'username' => 'acme']);
        $this->whmFailures['createacct'] = 'Access denied';

        try {
            app(SetUpService::class)->handle($service);
            $this->fail('The set up should have failed.');
        } catch (ProvisioningException) {
        }

        return $service;
    }

    public function test_rows_carry_the_one_thing_done_right_there(): void
    {
        $client = Client::factory()->create();
        $author = User::factory()->create(['role' => UserRole::Client, 'client_id' => $client->id]);
        Order::create(['client_id' => $client->id]);
        $old = app(Tickets::class)->open($client, $author, 'Oldest question', 'Hello', emailClient: false, asClient: true);
        $old->update(['last_reply_at' => now()->subDays(3)]);
        app(Tickets::class)->open($client, $author, 'Another', 'Hello', emailClient: false, asClient: true)->update(['last_reply_at' => now()->subDays(2)]);

        $this->assertSame(['label' => 'Complete it', 'call' => 'completeOrders'], $this->row('heroicon-o-shopping-cart')['action']);
        $this->assertSame(['label' => 'Reply', 'url' => TicketResource::getUrl('view', ['record' => $old])], $this->row('heroicon-o-chat-bubble-left-right')['action']);

        Order::create(['client_id' => $client->id]);
        $this->assertSame('Complete them', $this->row('heroicon-o-shopping-cart')['action']['label']);

        // The queue's Retry leaves terminations to the queue (they ask for the password)
        $service = Service::factory()->create();
        ModuleQueueItem::query()->create(['service_id' => $service->id, 'action' => ModuleQueueItem::TERMINATE, 'error' => 'x', 'attempts' => 1, 'last_attempt_at' => now()]);
        $this->assertNull($this->row('heroicon-o-arrow-path')['action']);
        ModuleQueueItem::query()->create(['service_id' => $service->id, 'action' => ModuleQueueItem::SUSPEND, 'error' => 'x', 'attempts' => 1, 'last_attempt_at' => now()]);
        $this->assertSame(['label' => 'Retry', 'call' => 'retryQueue'], $this->row('heroicon-o-arrow-path')['action']);

        // The row still opens its list, the button sits beside it
        Livewire::test(NeedsAttention::class)
            ->assertSeeHtml("wire:click=\"mountAction('completeOrders')\"")
            ->assertSeeHtml("wire:click=\"mountAction('retryQueue')\"")
            ->assertSeeHtml('href="'.TicketResource::getUrl('view', ['record' => $old]).'" class="gx-act"');
    }

    public function test_complete_order_runs_from_its_row(): void
    {
        $client = Client::factory()->create();
        $order = Order::create(['client_id' => $client->id]);
        $service = Service::factory()->for($client)->create(['order_id' => $order->id, 'status' => ServiceStatus::Pending, 'domain' => 'neworder.gr', 'username' => 'neworder']);

        Livewire::test(NeedsAttention::class)->callAction('completeOrders')->assertNotified('1 order completed');

        $this->assertSame(OrderStatus::Completed, $order->refresh()->status);
        $this->assertSame(ServiceStatus::Active, $service->refresh()->status);

        // One that fails stays pending and is named
        $second = Order::create(['client_id' => $client->id]);
        Service::factory()->for($client)->create(['order_id' => $second->id, 'status' => ServiceStatus::Pending, 'domain' => 'fails.gr', 'username' => 'fails', 'label' => 'Hosting fails.gr']);
        $this->whmFailures['createacct'] = 'Access denied';

        Livewire::test(NeedsAttention::class)->callAction('completeOrders')->assertNotified('0 orders completed');
        $this->assertSame(OrderStatus::Pending, $second->refresh()->status);

        // Without the Orders area the action does nothing
        $this->actingAs(User::factory()->limitedTo([AdminArea::Billing])->create());
        Livewire::test(NeedsAttention::class)->assertActionHidden('completeOrders');
    }

    public function test_retry_runs_the_queue_from_its_row(): void
    {
        $service = $this->failedSetUp();
        unset($this->whmFailures['createacct']);

        Livewire::test(NeedsAttention::class)->callAction('retryQueue')->assertNotified('1 done, 0 still failing');

        $this->assertSame(ServiceStatus::Active, $service->refresh()->status);
        $this->assertNotNull(ModuleQueueItem::query()->sole()->resolved_at);
    }

    public function test_recent_activity_lists_the_last_events_and_filters_by_kind(): void
    {
        $client = Client::factory()->create(['first_name' => 'Maria', 'last_name' => 'Pappa']);
        $service = Service::factory()->for($client)->create(['domain' => 'maria.gr']);
        ActivityLog::record('payment.recorded', 'Payment of 50.00 recorded', $service);
        ActivityLog::record('order.placed', 'Order #7 placed with 1 item', client: $client);
        ActivityLog::record('client.created', 'Client Maria Pappa created', client: $client);
        ActivityLog::record('service.updated', 'Not listed here', $service);
        ActivityLog::record('ticket.client_replied', 'Client replied to ticket T-000001', client: $client);

        $widget = new RecentActivity;
        $this->assertSame(
            ['Client replied to ticket T-000001', 'Client Maria Pappa created', 'Order #7 placed with 1 item', 'Payment of 50.00 recorded'],
            array_column($widget->rows(), 'title'),
        );
        $this->assertStringStartsWith('Maria Pappa · ', $widget->rows()[0]['sub']);
        $this->assertStringContainsString("/services/{$service->id}/edit", $widget->rows()[3]['url']);
        $this->assertStringContainsString("/clients/{$client->id}/edit", $widget->rows()[1]['url']);

        Livewire::test(RecentActivity::class)
            ->assertSee('Recent activity')
            ->assertSee('Order #7 placed')
            ->call('show', 'payments')
            ->assertSee('Payment of 50.00 recorded')
            ->assertDontSee('Order #7 placed')
            ->call('show', 'cancellations')
            ->assertSee('Nothing here yet')
            ->call('show', 'no-such-kind')
            ->assertSee('Order #7 placed');

        // No more than SHOWN rows
        foreach (range(1, RecentActivity::SHOWN + 3) as $i) {
            ActivityLog::record('payment.recorded', "Payment {$i}", $service);
        }
        $this->assertCount(RecentActivity::SHOWN, (new RecentActivity)->rows());

        // Only for admins who may open the Activity log
        $this->assertTrue(RecentActivity::canView());
        $this->actingAs(User::factory()->limitedTo([AdminArea::Billing])->create());
        $this->assertFalse(RecentActivity::canView());
    }

    public function test_the_servers_card_warns_near_the_maximum_and_shows_the_month_s_uptime(): void
    {
        $roomy = Server::factory()->create(['name' => 'roomy', 'max_accounts' => 100]);
        $tight = Server::factory()->create(['name' => 'tight', 'max_accounts' => 20]);
        $full = Server::factory()->create(['name' => 'packed', 'max_accounts' => 2]);
        Service::factory()->count(17)->create(['server_id' => $tight->id, 'status' => 'active', 'type' => 'hosting']);
        Service::factory()->count(2)->create(['server_id' => $full->id, 'status' => 'active', 'type' => 'hosting']);

        // 3 of 4 checks in the last 30 days were up; an older one is left out
        $roomy->update(['monitor_module' => 'uptime_kuma', 'monitor_ref' => '1']);
        foreach ([1 => 'up', 2 => 'down', 3 => 'up', 4 => 'up', 40 => 'down'] as $days => $status) {
            DB::table('server_monitor_checks')->insert(['server_id' => $roomy->id, 'status' => $status, 'checked_at' => now()->subDays($days)->utc()]);
        }
        $this->assertEquals([$roomy->id => 0.75], ServerCapacity::monthRatios([$roomy->id, $tight->id]));

        Livewire::test(ServerCapacity::class)
            ->assertSeeInOrder(['packed', 'Full', 'roomy', '75% in 30 days', 'tight', 'Nearly full'])
            ->assertDontSeeHtml('roomy</span>
                        <span class="gx-pill');

        // The monitoring service's own number wins
        $roomy->forceFill(['monitor_uptime' => ['30d' => 0.99914]])->save();
        Livewire::test(ServerCapacity::class)->assertSee('99.91% in 30 days');
    }
}
