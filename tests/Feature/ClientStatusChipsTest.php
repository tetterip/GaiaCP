<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Enums\ServiceStatus;
use App\Enums\UserRole;
use App\Filament\Client\Resources\Domains\Pages\ListDomains;
use App\Filament\Client\Resources\PaymentNotices\Pages\ListPaymentNotices;
use App\Filament\Client\Resources\Services\Pages\ListServices;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** The chips over the client area's Services, Domains and Payment notices lists, like the admin's. */
class ClientStatusChipsTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = Client::factory()->create();
        $this->actingAs(User::factory()->create(['role' => UserRole::Client, 'client_id' => $this->client->id, 'email' => $this->client->email]), 'client');
        Filament::setCurrentPanel('client');
    }

    /** @return array<string, int> chip => count */
    private function counts(object $page): array
    {
        return array_map(fn (array $chip) => $chip['count'], $page->instance()->statusChips());
    }

    private function notice(InvoiceStatus $status, string $due): Invoice
    {
        return Invoice::create([
            'number' => Invoice::nextNumber(now()),
            'client_id' => $this->client->id,
            'status' => $status,
            'date' => now()->subMonth(),
            'due_date' => $due,
            'currency' => 'EUR',
            'subtotal_cents' => 1000,
            'tax_cents' => 0,
            'total_cents' => 1000,
        ]);
    }

    public function test_services_chips_count_each_status_and_filter_the_list(): void
    {
        $active = Service::factory()->for($this->client)->create();
        $suspended = Service::factory()->for($this->client)->create(['status' => ServiceStatus::Suspended]);
        $terminated = Service::factory()->for($this->client)->create(['status' => ServiceStatus::Terminated]);
        Service::factory()->for($this->client)->create(['status' => ServiceStatus::Cancelled]);
        Service::factory()->domain()->for($this->client)->create();
        Service::factory()->create(['status' => ServiceStatus::Suspended]); // someone else's

        $list = Livewire::test(ListServices::class)->assertSee(__('Suspended'));

        // No Pending chip: the client has none
        $this->assertSame(['all' => 3, 'active' => 1, 'suspended' => 1, 'terminated' => 1], $this->counts($list));
        $this->assertTrue($list->instance()->statusChips()['all']['active']);

        $list->call('applyQuickFilter', 'suspended')
            ->assertCanSeeTableRecords([$suspended])
            ->assertCanNotSeeTableRecords([$active, $terminated]);
        $this->assertTrue($list->instance()->statusChips()['suspended']['active']);
        $this->assertFalse($list->instance()->statusChips()['all']['active']);

        $list->call('applyQuickFilter', 'all')->assertCanSeeTableRecords([$active, $suspended, $terminated]);
        $list->call('applyQuickFilter', 'nonsense')->assertCanSeeTableRecords([$active, $suspended, $terminated]);
    }

    public function test_no_chips_while_every_service_has_the_same_status(): void
    {
        Service::factory()->count(2)->for($this->client)->create();

        $this->assertSame([], Livewire::test(ListServices::class)->instance()->statusChips());
    }

    public function test_the_hosting_entry_counts_only_hosting(): void
    {
        Service::factory()->for($this->client)->create();
        Service::factory()->for($this->client)->create(['status' => ServiceStatus::Suspended]);
        Service::factory()->maintenance()->for($this->client)->create(['status' => ServiceStatus::Pending]);

        $this->assertSame(['all' => 2, 'active' => 1, 'suspended' => 1], $this->counts(Livewire::withQueryParams(['kind' => 'hosting'])->test(ListServices::class)));
    }

    public function test_domains_chips(): void
    {
        $active = Service::factory()->domain()->for($this->client)->create();
        $pending = Service::factory()->domain()->for($this->client)->create(['status' => ServiceStatus::Pending]);

        $list = Livewire::test(ListDomains::class);
        $this->assertSame(['all' => 2, 'active' => 1, 'pending' => 1], $this->counts($list));

        $list->call('applyQuickFilter', 'pending')->assertCanSeeTableRecords([$pending])->assertCanNotSeeTableRecords([$active]);
    }

    public function test_payment_notices_chips_include_overdue(): void
    {
        $overdue = $this->notice(InvoiceStatus::Unpaid, now()->subDays(5)->toDateString());
        $open = $this->notice(InvoiceStatus::Unpaid, now()->addDays(5)->toDateString());
        $paid = $this->notice(InvoiceStatus::Paid, now()->subDays(20)->toDateString());
        $this->notice(InvoiceStatus::Cancelled, now()->toDateString());

        $list = Livewire::test(ListPaymentNotices::class);
        $this->assertSame(['all' => 3, 'unpaid' => 2, 'paid' => 1, 'overdue' => 1], $this->counts($list));

        $list->call('applyQuickFilter', 'overdue')->assertCanSeeTableRecords([$overdue])->assertCanNotSeeTableRecords([$open, $paid]);
        $this->assertTrue($list->instance()->statusChips()['overdue']['active']);
        $this->assertFalse($list->instance()->statusChips()['unpaid']['active']);

        $list->call('applyQuickFilter', 'unpaid')->assertCanSeeTableRecords([$overdue, $open])->assertCanNotSeeTableRecords([$paid]);
        $list->call('applyQuickFilter', 'paid')->assertCanSeeTableRecords([$paid])->assertCanNotSeeTableRecords([$overdue, $open]);
    }
}
