<?php

namespace Tests\Feature;

use App\Enums\ServiceStatus;
use App\Filament\Resources\Services\Pages\ListServices;
use App\Filament\Resources\Services\Tables\ServicesTable;
use App\Models\Client;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

class ServicesListTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_tab_has_its_title_and_counts(): void
    {
        Service::factory()->count(2)->create();
        Service::factory()->create(['status' => ServiceStatus::Terminated]);
        Service::factory()->domain()->create();

        $this->actingAs(User::factory()->create());
        $this->get('/admin/services?tab=hosting')->assertOk()
            ->assertSee('The hosting accounts of all clients, on every server')
            ->assertSee('Export CSV')
            ->assertSee('Domain, username or client')
            ->assertSee('gx-chips', false);

        $page = Livewire::withQueryParams(['tab' => 'hosting'])->test(ListServices::class);
        $tabs = $page->instance()->getCachedTabs();
        // Running or pending services: the terminated one isn't counted
        $this->assertSame('2', (string) $tabs['hosting']->getBadge());
        $this->assertSame('1', (string) $tabs['domains']->getBadge());
        $this->assertSame('Hosting', $page->instance()->getHeading());
    }

    public function test_quick_filters_count_and_set_the_filters(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-17 10:00'));
        $active = Service::factory()->dueOn('2027-03-01')->create();
        $soon = Service::factory()->dueOn('2026-10-22')->create();
        $suspended = Service::factory()->dueOn('2027-03-01')->create(['status' => ServiceStatus::Suspended]);
        $domain = Service::factory()->domain()->dueOn('2026-10-20')->create();

        $this->actingAs(User::factory()->create());
        $page = Livewire::withQueryParams(['tab' => 'hosting'])->test(ListServices::class);

        $chips = $page->instance()->quickFilters();
        $this->assertSame(3, $chips['all']['count']);
        $this->assertSame(2, $chips['active']['count']);
        $this->assertSame(1, $chips['suspended']['count']);
        $this->assertSame(1, $chips['due_soon']['count']); // the domain is on another tab
        $this->assertTrue($chips['all']['active']);

        $page->call('applyQuickFilter', 'suspended')
            ->assertCanSeeTableRecords([$suspended])
            ->assertCanNotSeeTableRecords([$active, $soon]);
        $this->assertTrue($page->instance()->quickFilters()['suspended']['active']);
        $this->assertFalse($page->instance()->quickFilters()['all']['active']);

        $page->call('applyQuickFilter', 'due_soon')
            ->assertCanSeeTableRecords([$soon])
            ->assertCanNotSeeTableRecords([$active, $suspended, $domain]);

        $page->call('applyQuickFilter', 'all')
            ->assertCanSeeTableRecords([$active, $soon, $suspended]);
        $this->assertTrue($page->instance()->quickFilters()['all']['active']);
    }

    public function test_the_next_due_column_says_how_near_it_is(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-17 18:00'));

        $due = fn (string $date, ServiceStatus $status = ServiceStatus::Active) => ServicesTable::dueSoon(
            Service::factory()->make(['next_due_date' => $date, 'status' => $status])
        );

        $this->assertSame('Today', $due('2026-10-17'));
        $this->assertSame('Tomorrow', $due('2026-10-18'));
        $this->assertSame('In 5 days', $due('2026-10-22'));
        $this->assertNull($due('2026-11-30'));
        $this->assertSame('1 day overdue', $due('2026-10-16'));
        $this->assertSame('12 days overdue', $due('2026-10-05'));
        $this->assertNull($due('2026-10-05', ServiceStatus::Terminated));
    }

    public function test_export_csv_gives_the_rows_of_the_tab(): void
    {
        $client = Client::factory()->create(['first_name' => 'Maria', 'last_name' => 'Papadopoulou', 'company' => 'ACME']);
        Service::factory()->for($client)->create(['domain' => 'acme.gr', 'username' => 'acmegr', 'price_cents' => 12000]);
        Service::factory()->domain()->create(['domain' => 'other.gr']);

        $this->actingAs(User::factory()->create());
        $response = Livewire::withQueryParams(['tab' => 'hosting'])->test(ListServices::class)->instance()->csv();
        $this->assertInstanceOf(StreamedResponse::class, $response);

        ob_start();
        $response->sendContent();
        $rows = array_map(fn (string $line) => str_getcsv($line, escape: ''), array_filter(explode("\n", substr((string) ob_get_clean(), 3))));

        $this->assertSame('Service', $rows[0][0]);
        $this->assertCount(2, $rows);
        $this->assertSame(['acme.gr', 'Web hosting', 'Hosting', 'Maria Papadopoulou', 'ACME'], array_slice($rows[1], 0, 5));
        $this->assertSame('acmegr', $rows[1][7]);
        $this->assertSame('120.00', $rows[1][8]);
    }
}
