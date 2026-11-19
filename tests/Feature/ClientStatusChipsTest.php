<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Enums\ServiceStatus;
use App\Enums\ServiceType;
use App\Enums\UserRole;
use App\Filament\Client\Resources\Domains\DomainResource;
use App\Filament\Client\Resources\Domains\Pages\ListDomains;
use App\Filament\Client\Resources\PaymentNotices\Pages\ListPaymentNotices;
use App\Filament\Client\Resources\PaymentNotices\PaymentNoticeResource;
use App\Filament\Client\Resources\Services\Pages\ListServices;
use App\Filament\Client\Resources\Services\ServiceResource;
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
        $active = Service::factory()->domain()->for($this->client)->create(['domain' => 'active.gr', 'next_due_date' => today()->addYear()->toDateString()]);
        $pending = Service::factory()->domain()->for($this->client)->create(['domain' => 'pending.gr', 'status' => ServiceStatus::Pending]);

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

    public function test_domains_chips_for_expiring_auto_renew_off_and_each_extension(): void
    {
        $soon = Service::factory()->domain()->for($this->client)->create(['domain' => 'soon.gr', 'next_due_date' => today()->addDays(10)->toDateString()]);
        $off = Service::factory()->domain()->for($this->client)->create(['domain' => 'off.com', 'next_due_date' => today()->addDays(200)->toDateString(), 'auto_terminate' => true]);
        $greek = Service::factory()->domain()->for($this->client)->create(['domain' => 'shop.com.gr', 'next_due_date' => today()->addDays(100)->toDateString()]);
        $gone = Service::factory()->domain()->for($this->client)->create(['domain' => 'gone.gr', 'next_due_date' => today()->subDays(400)->toDateString(), 'status' => ServiceStatus::Terminated]);

        $list = Livewire::test(ListDomains::class);
        $this->assertSame(
            ['all' => 4, 'active' => 3, 'terminated' => 1, 'expiring' => 1, 'auto_renew_off' => 1, 'tld-gr' => 2, 'tld-com' => 1, 'tld-com.gr' => 1],
            $this->counts($list),
        );

        // The one ending first on top, the terminated one last
        $list->assertCanSeeTableRecords([$soon, $greek, $off, $gone], inOrder: true);

        $list->call('applyQuickFilter', 'expiring')->assertCanSeeTableRecords([$soon])->assertCanNotSeeTableRecords([$off, $greek, $gone]);
        $this->assertTrue($list->instance()->statusChips()['expiring']['active']);
        $this->assertFalse($list->instance()->statusChips()['all']['active']);

        $list->call('applyQuickFilter', 'auto_renew_off')->assertCanSeeTableRecords([$off])->assertCanNotSeeTableRecords([$soon, $greek]);
        $this->assertFalse($list->instance()->statusChips()['expiring']['active']);

        // .gr leaves out .com.gr
        $list->call('applyQuickFilter', 'tld-gr')->assertCanSeeTableRecords([$soon, $gone])->assertCanNotSeeTableRecords([$greek, $off]);
        $list->call('applyQuickFilter', 'tld-com.gr')->assertCanSeeTableRecords([$greek])->assertCanNotSeeTableRecords([$soon, $off]);

        $list->call('applyQuickFilter', 'all')->assertCanSeeTableRecords([$soon, $off, $greek, $gone]);
        $this->assertTrue($list->instance()->statusChips()['all']['active']);
    }

    public function test_no_extension_chips_with_a_single_extension(): void
    {
        Service::factory()->domain()->for($this->client)->create(['domain' => 'one.gr']);
        Service::factory()->domain()->for($this->client)->create(['domain' => 'two.gr', 'status' => ServiceStatus::Pending]);

        $this->assertArrayNotHasKey('tld-gr', Livewire::test(ListDomains::class)->instance()->statusChips());
    }

    public function test_other_services_chips_per_kind(): void
    {
        $maintenance = Service::factory()->maintenance()->for($this->client)->create();
        $ssl = Service::factory()->maintenance()->for($this->client)->create(['type' => ServiceType::Ssl, 'label' => 'SSL']);
        Service::factory()->for($this->client)->create(); // hosting: not in Other services

        $list = Livewire::withQueryParams(['kind' => 'other'])->test(ListServices::class);
        $this->assertSame(['all' => 2, 'active' => 2, 'type-maintenance' => 1, 'type-ssl' => 1], $this->counts($list));

        $list->call('applyQuickFilter', 'type-ssl')->assertCanSeeTableRecords([$ssl])->assertCanNotSeeTableRecords([$maintenance]);

        // Hosting has no kind chips
        $this->assertArrayNotHasKey('type-hosting', Livewire::withQueryParams(['kind' => 'hosting'])->test(ListServices::class)->instance()->statusChips());
    }

    public function test_sidebar_badges_count_only_what_needs_doing(): void
    {
        $this->assertNull(PaymentNoticeResource::getNavigationBadge());
        $this->assertNull(DomainResource::getNavigationBadge());

        $this->notice(InvoiceStatus::Unpaid, now()->addDays(5)->toDateString());
        $this->notice(InvoiceStatus::Unpaid, now()->subDays(5)->toDateString());
        $this->notice(InvoiceStatus::Paid, now()->subDays(5)->toDateString());
        $this->assertSame('2', PaymentNoticeResource::getNavigationBadge());

        // Auto renew off and ending within 30 days; one ending later and one renewing don't count
        Service::factory()->domain()->for($this->client)->create(['next_due_date' => today()->addDays(20)->toDateString(), 'auto_terminate' => true]);
        Service::factory()->domain()->for($this->client)->create(['next_due_date' => today()->addDays(90)->toDateString(), 'auto_terminate' => true]);
        Service::factory()->domain()->for($this->client)->create(['next_due_date' => today()->addDays(5)->toDateString()]);
        $this->assertSame('1', DomainResource::getNavigationBadge());

        Service::factory()->for($this->client)->create(['status' => ServiceStatus::Suspended]);
        Service::factory()->maintenance()->for($this->client)->create(['status' => ServiceStatus::Suspended]);
        Service::factory()->maintenance()->for($this->client)->create(['status' => ServiceStatus::Suspended]);
        $badges = collect(ServiceResource::getNavigationItems())->mapWithKeys(fn ($item) => [$item->getLabel() => $item->getBadge()]);
        $this->assertSame(['Hosting' => '1', 'Other services' => '2'], $badges->all());
    }
}
