<?php

namespace Tests\Feature;

use App\Enums\BillingCycle;
use App\Enums\CurrencyFormat;
use App\Enums\ServiceStatus;
use App\Enums\ServiceType;
use App\Filament\Widgets\ActivityChart;
use App\Models\Client;
use App\Models\Currency;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Server;
use App\Models\Service;
use App\Models\Tld;
use App\Models\User;
use App\Reports\IncomeForecast;
use App\Reports\IncomeReport;
use App\Reports\SalesOpportunities;
use App\Support\Currencies;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** The reports read grouped rows and small projections, not every payment or service with its relations (#81). */
class ReportQueriesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-10 12:00');
        $this->actingAs(User::factory()->create());
    }

    private function queries(callable $run): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $run();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    public function test_tlds_are_matched_longest_first_from_one_read(): void
    {
        $gr = Tld::query()->create(['tld' => '.gr']);
        $comGr = Tld::query()->create(['tld' => '.com.gr']);
        $old = Tld::query()->create(['tld' => '.old', 'active' => false]);
        $idn = Tld::query()->create(['tld' => '.ελ']);

        $this->assertTrue(Tld::match('shop.com.gr')->is($comGr));
        $this->assertTrue(Tld::match('www.shop.gr')->is($gr));
        $this->assertTrue(Tld::match('SHOP.GR.')->is($gr));
        $this->assertTrue(Tld::match('παράδειγμα.ελ')->is($idn));
        $this->assertTrue(Tld::match('xn--hxajbheg2az3al.xn--qxam')->is($idn), 'an IDN entered in ASCII');
        $asciiTld = Tld::query()->create(['tld' => '.xn--90ae']);
        $this->assertTrue(Tld::match('пример.бг')->is($asciiTld), 'an IDN TLD stored in ASCII');
        $this->assertNull(Tld::match('com.gr.example'));
        $this->assertNull(Tld::match('.gr'), 'the TLD alone is no domain');
        $this->assertNull(Tld::match(''));
        $this->assertNull(Tld::match('site.old'), 'not sold any more');
        $this->assertTrue(Tld::match('site.old', activeOnly: false)->is($old));

        $this->assertSame(0, $this->queries(function () {
            foreach (range(1, 50) as $i) {
                Tld::match("site{$i}.com.gr", activeOnly: false);
                Tld::match("site{$i}.gr");
            }
        }), 'the table is read once per request');

        $com = Tld::query()->create(['tld' => '.com']);
        $this->assertTrue(Tld::match('site.com')->is($com), 'a saved TLD is seen at once');

        $comGr->update(['active' => false]);
        $this->assertTrue(Tld::match('shop.com.gr')->is($gr));

        $gr->delete();
        $this->assertNull(Tld::match('shop.gr'));
    }

    public function test_winback_reads_the_tlds_once_for_every_domain(): void
    {
        Tld::query()->create(['tld' => '.gr', 'grace_days' => 15]);
        $client = Client::factory()->create();

        foreach (range(1, 20) as $i) {
            Service::factory()->domain()->for($client)->create(['domain' => "lapsed{$i}.gr", 'status' => ServiceStatus::Cancelled, 'next_due_date' => '2026-10-05']);
        }

        $count = $this->queries(fn () => $this->assertCount(20, SalesOpportunities::ids('winback')));

        $this->assertLessThanOrEqual(5, $count);
    }

    public function test_income_in_another_currency_is_converted_payment_by_payment(): void
    {
        Currency::create(['code' => 'USD', 'prefix' => '$', 'format' => CurrencyFormat::CommaThousands, 'rate' => 1.5]);
        Currencies::flush();
        $product = Product::factory()->create(['name' => 'Hosting Basic']);
        $hosting = Service::factory()->create(['product_id' => $product->id]);
        $domain = Service::factory()->domain()->create();

        // 1.00 USD = 0.67 EUR each: three of them are 2.01, not 3.00 / 1.5 = 2.00
        foreach (['2026-03-05', '2026-03-05', '2026-03-31'] as $day) {
            Payment::factory()->create(['service_id' => $hosting->id, 'amount_cents' => 100, 'currency' => 'USD', 'paid_on' => $day]);
        }
        Payment::factory()->create(['service_id' => $hosting->id, 'amount_cents' => 4000, 'paid_on' => '2026-03-31']);
        Payment::factory()->create(['service_id' => $domain->id, 'amount_cents' => 1500, 'paid_on' => '2026-01-01']);
        Payment::factory()->create(['service_id' => $domain->id, 'amount_cents' => 999, 'paid_on' => '2025-12-31']);
        Payment::factory()->create(['service_id' => $domain->id, 'amount_cents' => 999, 'paid_on' => '2027-01-01']);

        $report = new IncomeReport;
        $one = fn (Payment $p) => Currencies::toBase($p->amount_cents, $p->currency);
        $expected = $report->between(CarbonImmutable::create(2026), CarbonImmutable::create(2026)->endOfYear())->sum($one);

        $months = $report->monthly(2026);
        $this->assertSame(5701, $expected);
        $this->assertSame($expected, array_sum(array_column($months, 'cents')));
        $this->assertSame(['count' => 4, 'cents' => 4201], $months[3]);
        $this->assertSame(['count' => 1, 'cents' => 1500], $months[1], 'the first day of the year counts, the day before does not');
        $this->assertSame(['count' => 2, 'cents' => 4067], $report->daily(2026, 3)[31]);
        $this->assertSame(['2026-03-05' => 134, '2026-03-31' => 4067], $report->perDay(CarbonImmutable::parse('2026-03-01'), CarbonImmutable::parse('2026-03-31')));

        $products = $report->byProduct(CarbonImmutable::create(2026), CarbonImmutable::create(2026)->endOfYear());
        $this->assertSame(['Hosting Basic', 'No product'], $products->pluck('product')->all());
        $this->assertSame([4201, 1500], $products->pluck('cents')->all());
        $this->assertSame([4, 1], $products->pluck('count')->all());
        $this->assertSame(ServiceType::Domain->getLabel(), $products[1]['type']);

        $this->assertSame(1, $this->queries(fn () => $report->monthly(2026)), 'one grouped query');
    }

    public function test_the_activity_chart_buckets_orders_and_income_in_one_pass(): void
    {
        $client = Client::factory()->create();
        $service = Service::factory()->for($client)->create();
        Order::create(['client_id' => $client->id])->forceFill(['created_at' => '2026-10-09 09:00'])->save();
        Order::create(['client_id' => $client->id])->forceFill(['created_at' => '2026-05-02 09:00'])->save();
        Order::create(['client_id' => $client->id, 'status' => 'completed', 'completed_at' => '2026-10-10 08:00']);
        Payment::factory()->create(['service_id' => $service->id, 'amount_cents' => 2000, 'paid_on' => '2026-10-09']);
        Payment::factory()->create(['service_id' => $service->id, 'amount_cents' => 500, 'paid_on' => '2026-10-10']);
        Payment::factory()->create(['service_id' => $service->id, 'amount_cents' => 700, 'paid_on' => '2026-05-31']);
        Payment::factory()->create(['service_id' => $service->id, 'amount_cents' => 900, 'paid_on' => '2025-10-31']);

        $chart = new ActivityChart;

        $days = $chart->series('30d');
        $this->assertCount(30, $days);
        $this->assertSame(['orders' => 1, 'completed' => 0, 'income' => 2000], array_diff_key($days[28], ['label' => 0]));
        $this->assertSame(['orders' => 1, 'completed' => 1, 'income' => 500], array_diff_key($days[29], ['label' => 0]), 'the completed order was placed today too');
        $this->assertSame(2500, array_sum(array_column($days, 'income')));

        $months = $chart->series('12m');
        $this->assertSame([1, 0, 700], [$months[6]['orders'], $months[6]['completed'], $months[6]['income']], 'May 2026');
        $this->assertSame(2500, $months[11]['income']);
        $this->assertSame(3200, array_sum(array_column($months, 'income')), 'October 2025 is before the year');

        $hours = $chart->series('today');
        $this->assertSame(500, array_sum(array_column($hours, 'income')));
        $this->assertSame(1, $hours[8]['completed']);

        $chart->period = '12m';
        $this->assertSame(900, $chart->previousIncome());
    }

    public function test_the_forecast_months_and_servers_add_up_to_its_dues(): void
    {
        $server = Server::factory()->create(['name' => 'pl1']);
        Tld::query()->create(['tld' => '.gr', 'costs' => ['renew_1y_cents' => 800]]);

        Service::factory()->create(['server_id' => $server->id, 'price_cents' => 1000, 'billing_cycle' => BillingCycle::Monthly, 'start_date' => '2026-01-31', 'next_due_date' => '2026-10-31', 'new_price_cents' => 1200, 'new_price_from' => '2027-02-01']);
        Service::factory()->create(['server_id' => $server->id, 'price_cents' => 3000, 'billing_cycle' => BillingCycle::Quarterly, 'start_date' => '2026-09-01', 'next_due_date' => '2026-09-01']);
        Service::factory()->domain()->create(['domain' => 'site.gr', 'price_cents' => 1500, 'start_date' => '2025-12-01', 'next_due_date' => '2026-12-01']);
        Service::factory()->maintenance()->create(['price_cents' => 5000, 'billing_cycle' => BillingCycle::OneTime, 'start_date' => '2026-11-15', 'next_due_date' => '2026-11-15']);

        $forecast = new IncomeForecast;
        $dues = $forecast->dues(12)->reject(fn (array $d) => $d['overdue']);
        $months = $forecast->monthly(12);

        foreach ($months as $month) {
            $in = $dues->filter(fn (array $d) => $d['due']->isSameMonth($month['month']));
            $this->assertSame($in->count(), $month['count'], $month['month']->format('Y-m'));
            $this->assertSame((int) $in->sum('cents'), $month['cents']);
            $this->assertSame((int) $in->sum('cost'), $month['domain_costs']);
            $this->assertSame(array_sum($month['types']), $month['cents']);
        }

        $this->assertSame(800, $months[2]['domain_costs'], 'December: the domain\'s renewal cost');
        $this->assertSame(1200, $months[4]['types'][ServiceType::Hosting->value], 'February 2027: the new price');
        $this->assertSame(['count' => 1, 'cents' => 3000], $forecast->overdue());
        $this->assertSame(
            (int) $dues->filter(fn (array $d) => $d['server_id'] === $server->id)->sum('cents'),
            $forecast->byServer(12)->firstWhere('server', 'pl1')['forecast_cents'],
        );
    }

    /** The forecast reads plain rows: as many queries for 40 services as for 4, and one TLD lookup per ending. */
    public function test_the_forecast_reads_the_same_queries_whatever_the_number_of_services(): void
    {
        Tld::query()->create(['tld' => '.gr', 'costs' => ['renew_1y_cents' => 800]]);
        $server = Server::factory()->create();
        $make = fn (int $n) => Service::factory()->count($n)->sequence(
            fn ($s) => $s->index % 2 ? ['server_id' => $server->id, 'billing_cycle' => BillingCycle::Monthly] : ['type' => ServiceType::Domain, 'server_id' => null, 'domain' => "d{$s->index}.gr"],
        )->create();

        $make(4);
        (new IncomeForecast)->byTld(); // the TLD list is read once and kept
        $few = $this->queries(fn () => (new IncomeForecast)->monthly(12) && (new IncomeForecast)->byServer(12) && (new IncomeForecast)->byTld());
        $make(36);
        $many = $this->queries(fn () => (new IncomeForecast)->monthly(12) && (new IncomeForecast)->byServer(12) && (new IncomeForecast)->byTld());

        $this->assertSame($few, $many);
        $this->assertSame(20 * 800, (new IncomeForecast)->byTld()->firstWhere('tld', '.gr')['yearly_cost_cents']);
    }
}
