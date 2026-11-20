<?php

namespace Tests\Feature;

use App\Actions\ClientOrder;
use App\Enums\BillingCycle;
use App\Enums\ServiceStatus;
use App\Enums\ServiceType;
use App\Filament\Resources\Services\ServiceResource;
use App\Filament\Widgets\UpcomingRenewals;
use App\Models\Client;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Support\Health\Status;
use App\Support\Health\SystemHealth;
use App\Support\SetupGuide;
use App\Support\SpaLinks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

use function Filament\Support\is_app_url;

/** SPA mode of the panels and the repeated queries taken out of every page. */
class PanelSpeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_pages_of_a_panel_open_in_spa_mode_and_other_addresses_as_normal_pages(): void
    {
        // What FilamentView::hasSpaMode() asks of a link, with one panel's exceptions
        $spa = fn (string $panel, string $url): bool => ! str($url)->is(SpaLinks::exceptions($panel)) && is_app_url($url);

        $this->assertTrue($spa('admin', ServiceResource::getUrl()));
        $this->assertTrue($spa('admin', ServiceResource::getUrl('edit', ['record' => 5])));
        $this->assertTrue($spa('admin', url('admin/settings/servers')));
        // Not pages: they must not be fetched while the pointer rests on them
        $this->assertFalse($spa('admin', route('filament.admin.server-panel-login', ['server' => 1])));
        $this->assertFalse($spa('admin', route('filament.admin.ticket-file', ['attachment' => 1])));
        $this->assertFalse($spa('admin', url('admin/sw.js')));
        $this->assertFalse($spa('admin', url('api/v1/clients')));
        // The old bookmarks' redirect (/admin/{products|servers|…}) covers only its own words
        $this->assertFalse($spa('admin', url('admin/products')));
        $this->assertTrue($spa('admin', url('admin/clients')));

        $this->assertFalse($spa('client', url('admin/clients')), 'the other panel opens as a normal page');
        $this->assertFalse($spa('client', url('language/el')), 'a language switch is never fetched ahead');
        $this->assertFalse($spa('client', url('services/3/panel-login')));
        $this->assertFalse($spa('client', url('downloads/3/file')));
        $this->assertTrue($spa('client', url('payment-notices')));
        $this->assertTrue($spa('client', url('payment-notices/4')));
        $this->assertTrue($spa('client', url('/')));
    }

    public function test_the_admin_sidebar_links_navigate_without_a_full_page_load(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/admin')->assertOk()->assertSee('href="'.ServiceResource::getUrl().'" wire:navigate.hover', false);
    }

    public function test_the_products_a_client_can_order_are_read_once_per_request_and_forgotten_on_change(): void
    {
        $product = Product::factory()->create(['type' => ServiceType::Hosting, 'name' => 'Basic']);
        $this->assertSame(['Basic'], ClientOrder::products()->pluck('name')->all());

        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });
        ClientOrder::products();
        ClientOrder::products();
        $this->assertSame(0, $queries);

        $product->update(['name' => 'Basic plus']);
        $this->assertSame(['Basic plus'], ClientOrder::products()->pluck('name')->all());
    }

    public function test_the_renewals_card_renders_its_pay_buttons_without_a_query_per_row(): void
    {
        $this->actingAs(User::factory()->create());
        $client = Client::factory()->create();
        Service::factory()->count(6)->create([
            'client_id' => $client->id,
            'status' => ServiceStatus::Active,
            'billing_cycle' => BillingCycle::Monthly,
            'next_due_date' => today()->addDays(3)->toDateString(),
        ]);

        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = str_replace(['"', '`'], '', $query->sql); // SQLite quotes names with ", MySQL / MariaDB with `
        });
        Livewire::test(UpcomingRenewals::class)->assertOk();

        $byId = array_filter($queries, fn (string $sql) => str_contains($sql, 'from services') && str_contains($sql, 'services.id = ?'));
        $this->assertSame([], array_values($byId));
    }

    public function test_the_setup_guide_reads_its_settings_with_one_query(): void
    {
        $queries = 0;
        DB::listen(function ($query) use (&$queries) {
            $queries += str_contains(str_replace(['"', '`'], '', $query->sql), 'from settings') ? 1 : 0;
        });

        SetupGuide::steps();

        $this->assertSame(1, $queries);
    }

    public function test_system_health_names_what_makes_pages_quicker(): void
    {
        $checks = app(SystemHealth::class)->checks()->where('group', 'Speed')->keyBy('name');

        $this->assertSame(Status::Info, $checks['OPcache']->status); // the command line can't tell
        $this->assertStringContainsString('Not cached: config', $checks['Cached config, routes, components']->value);

        config(['cache.default' => 'database', 'session.driver' => 'database']);
        $this->assertStringContainsString('CACHE_STORE=file', app(SystemHealth::class)->checks()->firstWhere('name', 'Cache and sessions')->hint);

        config(['cache.default' => 'file', 'session.driver' => 'file']);
        $this->assertSame(Status::Ok, app(SystemHealth::class)->checks()->firstWhere('name', 'Cache and sessions')->status);
    }
}
