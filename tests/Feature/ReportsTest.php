<?php

namespace Tests\Feature;

use App\Enums\BillingCycle;
use App\Enums\DomainRegistrationType;
use App\Enums\ServiceStatus;
use App\Filament\Clusters\Reports\Pages\AnnualIncome;
use App\Filament\Clusters\Reports\Pages\DomainProfit;
use App\Filament\Clusters\Reports\Pages\DomainsReport;
use App\Filament\Clusters\Reports\Pages\IncomeByProduct;
use App\Filament\Clusters\Reports\Pages\IncomeForecast;
use App\Filament\Clusters\Reports\Pages\MonthlyTransactions;
use App\Filament\Clusters\Reports\Pages\ServerRevenue;
use App\Filament\Clusters\Reports\Pages\ServicesReport;
use App\Filament\Clusters\Reports\ReportsCluster;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Registrar;
use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use App\Reports\IncomeForecast as Forecast;
use App\Reports\IncomeReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

class ReportsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-10 12:00');
        $this->actingAs(User::factory()->create());
    }

    private function pay(Service $service, int $cents, string $on): Payment
    {
        return Payment::factory()->create(['service_id' => $service->id, 'amount_cents' => $cents, 'paid_on' => $on]);
    }

    /** @return list<list<string>> the CSV's rows, header first */
    private function csv(StreamedResponse $response): array
    {
        ob_start();
        $response->sendContent();
        $content = (string) ob_get_clean();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);

        return array_map(fn (string $line) => str_getcsv($line, escape: ''), array_filter(explode("\n", substr($content, 3))));
    }

    public function test_income_is_grouped_by_month_day_and_product(): void
    {
        $product = Product::factory()->create(['name' => 'Hosting Basic']);
        $hosting = Service::factory()->create(['product_id' => $product->id]);
        $domain = Service::factory()->domain()->create();

        $this->pay($hosting, 6000, '2026-03-05');
        $this->pay($hosting, 6000, '2026-03-20');
        $this->pay($domain, 1500, '2026-03-20');
        $this->pay($domain, 1500, '2025-03-01');

        $report = new IncomeReport;

        $this->assertSame(['count' => 3, 'cents' => 13500], $report->monthly(2026)[3]);
        $this->assertSame(['count' => 0, 'cents' => 0], $report->monthly(2026)[4]);
        $this->assertSame(['count' => 2, 'cents' => 7500], $report->daily(2026, 3)[20]);
        $this->assertSame([2026, 2025], $report->years());

        $products = $report->byProduct(now()->startOfYear(), now()->endOfYear());
        $this->assertSame(['Hosting Basic', 'No product'], $products->pluck('product')->all());
        $this->assertSame([12000, 1500], $products->pluck('cents')->all());
    }

    public function test_the_forecast_repeats_each_renewal_and_leaves_out_what_ends(): void
    {
        $server = Server::factory()->create(['name' => 'pl1']);

        // Monthly 10€ from 15/10: 12 renewals in Oct 2026 – Sep 2027.
        Service::factory()->create(['server_id' => $server->id, 'price_cents' => 1000, 'billing_cycle' => BillingCycle::Monthly, 'start_date' => '2026-09-15', 'next_due_date' => '2026-10-15']);
        // Yearly domain due in January.
        Service::factory()->domain()->create(['price_cents' => 1500, 'start_date' => '2025-01-20', 'next_due_date' => '2027-01-20']);
        // Overdue since September: one cycle overdue, the next one in the forecast.
        Service::factory()->maintenance()->create(['price_cents' => 5000, 'billing_cycle' => BillingCycle::Monthly, 'start_date' => '2026-08-01', 'next_due_date' => '2026-09-01']);
        // Ending: nothing more to come. Terminated: not billed.
        Service::factory()->create(['price_cents' => 9900, 'auto_terminate' => true, 'next_due_date' => '2026-11-01']);
        Service::factory()->create(['price_cents' => 9900, 'status' => ServiceStatus::Terminated, 'next_due_date' => '2026-11-01']);

        $forecast = new Forecast;
        $months = $forecast->monthly(12);

        $this->assertCount(12, $months);
        $this->assertSame('2026-10', $months[0]['month']->format('Y-m'));
        $this->assertSame(1000, $months[0]['cents']); // the maintenance due 01/10 is overdue already
        $this->assertSame(['hosting' => 1000, 'domain' => 1500, 'maintenance' => 5000, 'ssl' => 0, 'other' => 0], $months[3]['types']); // January
        $this->assertSame(12 * 1000 + 1500 + 11 * 5000, array_sum(array_column($months, 'cents')));
        $this->assertSame(['count' => 2, 'cents' => 10000], $forecast->overdue()); // September and October

        $servers = $forecast->byServer(12);
        $this->assertSame('pl1', $servers[0]['server']);
        $this->assertSame(1, $servers[0]['accounts']);
        $this->assertSame(12000, $servers[0]['yearly_cents']);
        $this->assertSame(12000, $servers[0]['forecast_cents']);
    }

    public function test_every_report_opens_from_the_reports_page(): void
    {
        $service = Service::factory()->create();
        $this->pay($service, 6000, '2026-10-02');
        Service::factory()->domain()->create(['domain' => 'example.gr']);

        $this->get(ReportsCluster::getUrl())->assertOk()
            ->assertSee('Annual income')->assertSee('Income forecast')->assertSee('Domains');

        foreach ([AnnualIncome::class, MonthlyTransactions::class, IncomeByProduct::class, IncomeForecast::class, ServerRevenue::class, DomainProfit::class, DomainsReport::class, ServicesReport::class] as $page) {
            $this->get($page::getUrl())->assertOk();
        }

        $this->get(MonthlyTransactions::getUrl())->assertSee('02/10/2026')->assertSee($service->label);
        $this->get(DomainsReport::getUrl())->assertSee('example.gr');
    }

    public function test_annual_income_exports_its_months(): void
    {
        $this->pay(Service::factory()->create(), 6000, '2026-02-10');
        $this->pay(Service::factory()->create(), 4000, '2025-02-10');

        $page = Livewire::test(AnnualIncome::class)->assertSet('year', 2026)->assertSee('+50%');
        $rows = $this->csv($page->instance()->csv());

        $this->assertSame(['Month', 'Payments', '2026', '2025', 'Change'], $rows[0]);
        $this->assertSame(['February', '1', '60.00', '40.00', '+50%'], $rows[2]);
        $this->assertSame(['Total', '1', '60.00', '40.00', '+50%'], $rows[13]);

        Livewire::test(AnnualIncome::class)->set('year', 2025)->assertSee('2024');
    }

    public function test_monthly_transactions_export_each_payment(): void
    {
        $service = Service::factory()->create(['label' => 'Web hosting', 'domain' => 'shop.gr']);
        $this->pay($service, 6000, '2026-10-02');
        $this->pay($service, 1234, '2026-09-30');

        $rows = $this->csv(Livewire::test(MonthlyTransactions::class)->instance()->csv());

        $this->assertCount(2, $rows);
        $this->assertSame(['02/10/2026', $service->client->name, 'Web hosting', 'shop.gr', 'Bank transfer', '', '60.00'], $rows[1]);
    }

    public function test_income_by_product_follows_the_period(): void
    {
        $this->pay(Service::factory()->create(), 6000, '2026-10-02');
        $this->pay(Service::factory()->create(), 1000, '2025-10-02');

        Livewire::test(IncomeByProduct::class)
            ->assertSet('from', '2026-01-01')
            ->assertSee('€60.00')
            ->call('preset', 'last_year')
            ->assertSet('until', '2025-12-31')
            ->assertSee('€10.00')
            ->assertDontSee('€60.00');
    }

    public function test_the_domains_report_shows_the_chosen_fields_and_filters_by_registrar(): void
    {
        $easy = Registrar::factory()->withTlds(['.gr'])->create(['name' => 'Easy.gr']);
        $other = Registrar::factory()->create(['name' => 'Other', 'is_default' => false]);

        $byTld = Service::factory()->domain()->create(['domain' => 'bytld.gr', 'next_due_date' => '2027-01-01']);
        $chosen = Service::factory()->domain()->create(['domain' => 'chosen.gr', 'next_due_date' => '2027-02-01']);
        $chosen->registration()->update(['registrar_id' => $other->id, 'expires_at' => '2027-02-01', 'nameservers' => ['ns1.x.gr', 'ns2.x.gr']]);
        $tracked = Service::factory()->domain()->create(['domain' => 'tracked.gr', 'next_due_date' => '2027-03-01']);
        $tracked->registration()->update(['registration_type' => DomainRegistrationType::External]);
        Service::factory()->create(['domain' => 'hosting.gr']); // not a domain

        $page = Livewire::test(DomainsReport::class)
            ->assertCanSeeTableRecords([$byTld, $chosen, $tracked])
            ->assertCanNotSeeTableRecords(Service::query()->where('domain', 'hosting.gr')->get());

        $rows = $this->csv($page->instance()->csv());
        $this->assertSame(['Client', 'Domain', 'Registration period', 'Expiry date', 'Next due date', 'Registrar', 'Status'], $rows[0]);
        $this->assertSame(['bytld.gr', 'Easy.gr'], [$rows[1][1], $rows[1][5]]);
        $this->assertSame(['chosen.gr', '1 year', '01/02/2027', 'Other'], [$rows[2][1], $rows[2][2], $rows[2][3], $rows[2][5]]);
        $this->assertSame('', $rows[3][5]); // tracked: no registrar of ours

        // Easy.gr: picked by TLD, not the domain set to another account nor the tracked one.
        Livewire::test(DomainsReport::class)
            ->filterTable('registrar', [$easy->id])
            ->assertCanSeeTableRecords([$byTld])
            ->assertCanNotSeeTableRecords([$chosen, $tracked]);

        // An extra field goes into the CSV too.
        $page = Livewire::test(DomainsReport::class)->toggleAllTableColumns(false)->toggleAllTableColumns()
            ->filterTable('domainDetails_expires_at_range', ['from' => '2027-01-15', 'until' => '2027-12-31']);
        $page->assertCanSeeTableRecords([$chosen])->assertCanNotSeeTableRecords([$byTld, $tracked]);
        $rows = $this->csv($page->instance()->csv());
        $this->assertContains('Nameservers', $rows[0]);
        $this->assertContains('ns1.x.gr, ns2.x.gr', $rows[1]);
        $this->assertCount(2, $rows);
    }

    public function test_the_services_report_leaves_out_domains_and_exports_what_is_filtered(): void
    {
        $active = Service::factory()->create(['domain' => 'active.gr', 'price_cents' => 6000]);
        $suspended = Service::factory()->create(['domain' => 'suspended.gr', 'status' => ServiceStatus::Suspended]);
        $maintenance = Service::factory()->maintenance()->create(['domain' => 'care.gr']);
        $domain = Service::factory()->domain()->create(['domain' => 'name.gr']);
        $this->pay($active, 6000, '2026-01-01');

        Livewire::test(ServicesReport::class)
            ->assertCanSeeTableRecords([$active, $suspended, $maintenance])
            ->assertCanNotSeeTableRecords([$domain]);

        $page = Livewire::test(ServicesReport::class)
            ->filterTable('status', [ServiceStatus::Active->value])
            ->filterTable('type', ['hosting'])
            ->toggleAllTableColumns(false)->toggleAllTableColumns()
            ->assertCanSeeTableRecords([$active])
            ->assertCanNotSeeTableRecords([$suspended, $maintenance]);

        $rows = $this->csv($page->instance()->csv());
        $this->assertCount(2, $rows);
        $paid = array_search('Paid to date', $rows[0], true);
        $this->assertSame('60.00', $rows[1][$paid]);
        $this->assertNotContains('Password', $rows[0]);
    }
}
