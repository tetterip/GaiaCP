<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Filament\Resources\Clients\Pages\EditClient;
use App\Filament\Resources\Clients\RelationManagers\OrdersRelationManager;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Models\Client;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Status chips over the orders, like the payment notices': All, Pending, Completed, Cancelled with counts. */
class OrderStatusChipsTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    /** @var array<string, Order> */
    private array $orders;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
        $this->client = Client::factory()->create();
        $other = Client::factory()->create();

        $this->orders = [
            'pending' => Order::create(['client_id' => $this->client->id, 'status' => OrderStatus::Pending]),
            'completed' => Order::create(['client_id' => $this->client->id, 'status' => OrderStatus::Completed]),
            'cancelled' => Order::create(['client_id' => $this->client->id, 'status' => OrderStatus::Cancelled]),
            'elsewhere' => Order::create(['client_id' => $other->id, 'status' => OrderStatus::Pending]),
        ];
    }

    public function test_the_orders_list_has_chips_that_filter_it(): void
    {
        $list = Livewire::test(ListOrders::class)->assertSee(__('All orders'))->assertCanSeeTableRecords(array_values($this->orders));

        $chips = $list->instance()->statusChips();
        $this->assertSame(['all' => 4, 'pending' => 2, 'completed' => 1, 'cancelled' => 1], array_map(fn ($chip) => $chip['count'], $chips));
        $this->assertTrue($chips['all']['active']);

        $list->call('applyQuickFilter', 'pending')
            ->assertCanSeeTableRecords([$this->orders['pending'], $this->orders['elsewhere']])
            ->assertCanNotSeeTableRecords([$this->orders['completed'], $this->orders['cancelled']]);
        $this->assertTrue($list->instance()->statusChips()['pending']['active']);
        $this->assertFalse($list->instance()->statusChips()['all']['active']);

        $list->call('applyQuickFilter', 'cancelled')->assertCanSeeTableRecords([$this->orders['cancelled']])->assertCanNotSeeTableRecords([$this->orders['pending']]);
        $list->call('applyQuickFilter', 'all')->assertCanSeeTableRecords(array_values($this->orders));
        $list->call('applyQuickFilter', 'nonsense')->assertCanSeeTableRecords(array_values($this->orders));
    }

    public function test_the_client_pages_orders_tab_counts_only_that_clients_orders(): void
    {
        $tab = Livewire::test(OrdersRelationManager::class, ['ownerRecord' => $this->client, 'pageClass' => EditClient::class]);

        $this->assertSame(['all' => 3, 'pending' => 1, 'completed' => 1, 'cancelled' => 1], array_map(fn ($chip) => $chip['count'], $tab->instance()->statusChips()));

        $tab->call('applyQuickFilter', 'completed')
            ->assertCanSeeTableRecords([$this->orders['completed']])
            ->assertCanNotSeeTableRecords([$this->orders['pending'], $this->orders['cancelled']]);
    }
}
