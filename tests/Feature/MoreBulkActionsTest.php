<?php

namespace Tests\Feature;

use App\Enums\CancellationType;
use App\Enums\ChangeRequestStatus;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Filament\Resources\Announcements\Pages\ListAnnouncements;
use App\Filament\Resources\CancellationRequests\Pages\ListCancellationRequests;
use App\Filament\Resources\IpBans\Pages\ListIpBans;
use App\Filament\Resources\KbArticles\Pages\ListKbArticles;
use App\Filament\Resources\ModuleQueue\Pages\ListModuleQueue;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Filament\Resources\Tickets\Pages\ListTickets;
use App\Filament\Resources\UpgradeRequests\Pages\ListUpgradeRequests;
use App\Models\Announcement;
use App\Models\CancellationRequest;
use App\Models\Client;
use App\Models\IpBan;
use App\Models\KbArticle;
use App\Models\KbCategory;
use App\Models\ModuleQueueItem;
use App\Models\Product;
use App\Models\Service;
use App\Models\Ticket;
use App\Models\TicketDepartment;
use App\Models\UpgradeRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/** Mass actions on tickets, the module queue, requests, products, announcements, articles and IP bans. */
class MoreBulkActionsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->admin = User::factory()->create();
        $this->actingAs($this->admin);
    }

    private function ticket(array $attributes = []): Ticket
    {
        return Ticket::query()->create($attributes + [
            'client_id' => Client::factory()->create()->id, 'subject' => 'Mail down', 'status' => TicketStatus::Open,
            'priority' => TicketPriority::Medium, 'last_reply_at' => now(),
        ]);
    }

    public function test_tickets_are_closed_assigned_and_changed(): void
    {
        $open = $this->ticket();
        $closed = $this->ticket(['status' => TicketStatus::Closed]);
        $department = TicketDepartment::query()->create(['name' => 'Billing']);
        $list = fn () => Livewire::test(ListTickets::class)->set('activeTab', 'all');

        $list()->callTableBulkAction('assign', [$open, $closed], ['assigned_to' => $this->admin->id])->assertNotified('2 tickets changed');
        $list()->callTableBulkAction('priority', [$open], ['priority' => TicketPriority::High->value])->assertNotified('1 ticket changed');
        $list()->callTableBulkAction('department', [$open], ['department_id' => $department->id])->assertNotified('1 ticket changed');
        $list()->callTableBulkAction('close', [$open, $closed])->assertNotified('1 ticket closed');

        $open->refresh();
        $this->assertSame($this->admin->id, $open->assigned_to);
        $this->assertSame(TicketPriority::High, $open->priority);
        $this->assertSame($department->id, $open->department_id);
        $this->assertSame(TicketStatus::Closed, $open->status);
        $this->assertDatabaseHas('activity_logs', ['event' => 'ticket.updated']);
    }

    public function test_module_queue_items_are_marked_resolved_and_terminations_are_not_retried_together(): void
    {
        $service = Service::factory()->maintenance()->create();
        $setup = ModuleQueueItem::query()->create(['service_id' => $service->id, 'action' => ModuleQueueItem::SUSPEND, 'error' => 'x', 'attempts' => 1, 'last_attempt_at' => now()]);
        $terminate = ModuleQueueItem::query()->create(['service_id' => $service->id, 'action' => ModuleQueueItem::TERMINATE, 'error' => 'x', 'attempts' => 1, 'last_attempt_at' => now()]);

        Livewire::test(ListModuleQueue::class)
            ->callTableBulkAction('retry', [$terminate])
            ->assertNotified('0 actions done');
        $this->assertNull($terminate->fresh()->resolved_at);

        Livewire::test(ListModuleQueue::class)
            ->callTableBulkAction('resolve', [$setup, $terminate])
            ->assertNotified('2 items marked resolved');
        $this->assertNotNull($setup->fresh()->resolved_at);
    }

    public function test_requests_are_approved_or_declined_together(): void
    {
        $service = Service::factory()->maintenance()->create();
        $cancel = CancellationRequest::query()->create(['service_id' => $service->id, 'client_id' => $service->client_id, 'type' => CancellationType::EndOfPeriod, 'status' => ChangeRequestStatus::Pending]);
        $other = Service::factory()->maintenance()->create();
        $decline = CancellationRequest::query()->create(['service_id' => $other->id, 'client_id' => $other->client_id, 'type' => CancellationType::EndOfPeriod, 'status' => ChangeRequestStatus::Pending]);

        Livewire::test(ListCancellationRequests::class)->callTableBulkAction('approve', [$cancel])->assertNotified('1 request approved');
        Livewire::test(ListCancellationRequests::class)->callTableBulkAction('decline', [$decline])->assertNotified('1 request declined');
        $this->assertTrue($service->fresh()->auto_terminate);
        $this->assertSame(ChangeRequestStatus::Rejected, $decline->fresh()->status);
        $this->assertFalse($other->fresh()->auto_terminate);

        $upgrade = UpgradeRequest::query()->create(['service_id' => $service->id, 'client_id' => $service->client_id, 'product_id' => Product::factory()->create()->id, 'price_cents' => 1000, 'status' => ChangeRequestStatus::Pending]);
        Livewire::test(ListUpgradeRequests::class)->callTableBulkAction('decline', [$upgrade])->assertNotified('1 request declined');
        $this->assertSame(ChangeRequestStatus::Rejected, $upgrade->fresh()->status);
    }

    public function test_products_are_hidden_and_retired_together(): void
    {
        $products = Product::factory()->count(2)->create();

        Livewire::test(ListProducts::class)->callTableBulkAction('hide', $products)->assertNotified('2 products hidden');
        Livewire::test(ListProducts::class)->callTableBulkAction('retire', $products)->assertNotified('2 products retired');

        $this->assertTrue($products[0]->fresh()->hidden);
        $this->assertTrue($products[1]->fresh()->retired);
    }

    public function test_announcements_and_articles_are_published_and_unpublished(): void
    {
        $draft = Announcement::create(['texts' => ['en' => ['title' => 'New data centre', 'body' => 'Moved.']]]);
        Livewire::test(ListAnnouncements::class)->callTableBulkAction('publish', [$draft])->assertNotified('1 announcement published');
        $this->assertNotNull($draft->fresh()->published_at);
        Livewire::test(ListAnnouncements::class)->callTableBulkAction('unpublish', [$draft])->assertNotified('1 announcement unpublished');
        $this->assertNull($draft->fresh()->published_at);

        $category = KbCategory::create(['names' => ['en' => 'Email']]);
        $article = KbArticle::create(['kb_category_id' => $category->id, 'texts' => ['en' => ['title' => 'Mailbox', 'body' => 'Open it.']]]);
        Livewire::test(ListKbArticles::class)->callTableBulkAction('publish', [$article])->assertNotified('1 article published');
        $this->assertTrue($article->fresh()->published);
    }

    public function test_ip_bans_are_lifted_together(): void
    {
        $bans = collect(['192.0.2.1', '192.0.2.2'])->map(fn (string $ip) => IpBan::query()->create(['ip' => $ip, 'attempts' => 3, 'expires_at' => now()->addDay()]));

        Livewire::test(ListIpBans::class)->callTableBulkAction('lift', $bans)->assertNotified('2 bans lifted');

        $this->assertSame(0, IpBan::query()->count());
    }
}
