<?php

namespace Tests\Feature;

use App\Actions\Quotes;
use App\Enums\AdminArea;
use App\Enums\BillingCycle;
use App\Enums\CancellationType;
use App\Enums\QuoteStatus;
use App\Enums\ServiceType;
use App\Enums\TicketStatus;
use App\Filament\Pages\Dashboard;
use App\Filament\Widgets\ActivityChart;
use App\Filament\Widgets\BillingOverview;
use App\Filament\Widgets\NeedsAttention;
use App\Filament\Widgets\ServerCapacity;
use App\Models\ActivityLog;
use App\Models\CancellationRequest;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Server;
use App\Models\Service;
use App\Models\Ticket;
use App\Models\UpgradeRequest;
use App\Models\User;
use App\Monitoring\MonitorStatus;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private function notice(string $due, int $cents = 12000): Invoice
    {
        static $number = 0;

        return Invoice::create([
            'number' => 'PF-2026-'.str_pad((string) ++$number, 4, '0', STR_PAD_LEFT),
            'client_id' => Client::factory()->create()->id,
            'date' => today()->subDays(20)->toDateString(),
            'due_date' => $due,
            'currency' => 'EUR',
            'subtotal_cents' => $cents,
            'tax_cents' => 0,
            'total_cents' => $cents,
        ]);
    }

    public function test_it_greets_the_admin_and_shows_the_cards(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-17 18:30'));
        Server::factory()->create(['name' => 'nl1', 'max_accounts' => 120]);

        $this->actingAs(User::factory()->create(['name' => 'Maria Papadopoulou']));
        $this->get(Dashboard::getUrl())->assertOk()
            ->assertSee('Good evening, Maria')
            ->assertSee('Saturday 17 October · what needs your attention today')
            ->assertSee('Open balance')
            ->assertSee('Orders and income')
            ->assertSee('nl1')
            // The accounts bar is named, with its value, like the uptime bar.
            ->assertSee('aria-label="Accounts: 0/120 (0%)"', false)
            ->assertSeeInOrder(['Accounts', '0/120 (0%)'])
            // Nothing waits, so the Needs attention card is left off.
            ->assertDontSee('gx-slot-NeedsAttention', false)
            ->assertSee('gx-dash', false);
    }

    public function test_the_balance_card_adds_up_unpaid_notices(): void
    {
        $this->notice(today()->subDays(3)->toDateString(), 15000);
        $this->notice(today()->addDays(10)->toDateString(), 4500);

        $this->actingAs(User::factory()->create());
        Livewire::test(BillingOverview::class)
            ->assertSee('€195.00')
            ->assertSee('in 2 unpaid payment notices')
            ->assertSee('€150.00')
            ->assertSee('1 notice');
    }

    public function test_the_chart_counts_orders_and_income_per_day_and_compares_the_period_before(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-17 12:00'));
        $client = Client::factory()->create();
        Order::create(['client_id' => $client->id])->forceFill(['created_at' => '2026-10-16 09:00'])->save();
        Order::create(['client_id' => $client->id, 'status' => 'completed', 'completed_at' => '2026-10-17 10:00']);
        Payment::factory()->create(['paid_on' => '2026-10-17', 'amount_cents' => 5000]);
        Payment::factory()->create(['paid_on' => '2026-10-11', 'amount_cents' => 3000]);
        Payment::factory()->create(['paid_on' => '2026-10-05', 'amount_cents' => 4000]); // the 7 days before

        $chart = new ActivityChart;
        $chart->period = '7d';
        $series = $chart->series();

        $this->assertCount(7, $series);
        $this->assertSame(['label' => '17/10', 'orders' => 1, 'completed' => 1, 'income' => 5000], end($series));
        $this->assertSame(1, $series[5]['orders']);
        $this->assertSame(3000, $series[0]['income']);
        $this->assertSame(4000, $chart->previousIncome());

        $chart->period = '12m';
        $this->assertCount(12, $chart->series());
        $this->assertSame(12000, array_sum(array_column($chart->series(), 'income')));

        $this->actingAs(User::factory()->create());
        Livewire::test(ActivityChart::class)
            ->assertSet('period', '30d')
            ->call('setPeriod', '7d')
            ->assertSet('period', '7d')
            ->assertSee('€80.00')
            ->assertSee('+100%')
            ->call('setPeriod', 'forever')
            ->assertSet('period', '7d');
    }

    public function test_today_counts_per_hour_and_compares_yesterday_by_the_same_time(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-17 14:30'));
        $client = Client::factory()->create();
        Order::create(['client_id' => $client->id])->forceFill(['created_at' => '2026-10-17 09:15'])->save();
        Order::create(['client_id' => $client->id])->forceFill(['created_at' => '2026-10-16 09:15'])->save();
        Payment::factory()->create(['paid_on' => '2026-10-17', 'amount_cents' => 5000, 'created_at' => '2026-10-17 11:05']);
        Payment::factory()->create(['paid_on' => '2026-10-16', 'amount_cents' => 2000, 'created_at' => '2026-10-16 10:00']);
        Payment::factory()->create(['paid_on' => '2026-10-16', 'amount_cents' => 9000, 'created_at' => '2026-10-16 20:00']); // after 14:30
        Payment::factory()->create(['paid_on' => '2026-10-16', 'amount_cents' => 1000, 'created_at' => '2026-10-17 08:00']); // backdated: start of its day

        $chart = new ActivityChart;
        $chart->period = 'today';
        $buckets = $chart->buckets();
        $series = $chart->series();

        $this->assertCount(24, $series);
        $this->assertSame(['label' => '09:00', 'orders' => 1, 'completed' => 0, 'income' => 0], $series[9]);
        $this->assertSame(5000, $series[11]['income']);
        $this->assertSame(5000, array_sum(array_column($series, 'income')));
        $this->assertFalse($buckets[14]['future']);
        $this->assertTrue($buckets[15]['future']);
        $this->assertSame(3000, $chart->previousIncome());

        $this->actingAs(User::factory()->create());
        Livewire::test(ActivityChart::class)
            ->call('setPeriod', 'today')
            ->assertSet('period', 'today')
            ->assertSee('yesterday by this time')
            ->assertSee('+67%');
        $this->assertSame('today', session('dashboard.activity_period'));
    }

    public function test_the_chart_and_the_servers_squares_are_set_up_to_animate_in(): void
    {
        $this->actingAs(User::factory()->create());
        Order::create(['client_id' => Client::factory()->create()->id]);

        // Keyed by period, so a new period draws again; each bar carries its place for the delay
        Livewire::test(ActivityChart::class)
            ->assertSeeHtml('wire:key="activity-plot-30d"')
            ->assertSeeHtml('style="--gx-i: 29"')
            ->call('setPeriod', '7d')
            ->assertSeeHtml('wire:key="activity-plot-7d"');

        // Only the lit squares light up one by one: 1 of 2 accounts fills half of them
        $server = Server::factory()->create(['max_accounts' => 2]);
        Service::factory()->create(['server_id' => $server->id, 'status' => 'active', 'type' => 'hosting']);
        $html = Livewire::test(ServerCapacity::class)->html();
        $this->assertSame(ServerCapacity::SQUARES / 2, preg_match_all('/\sdata-on\s/', $html));
        $this->assertStringContainsString('prefers-reduced-motion: reduce', view('filament.dashboard.styles')->render());
    }

    public function test_the_servers_squares_fill_the_card_but_stay_small_in_a_table_column(): void
    {
        $this->actingAs(User::factory()->create());
        $server = Server::factory()->create(['max_accounts' => 10]);

        // On the card the squares grow with its width (side by side once wide enough); no fixed width
        $html = Livewire::test(ServerCapacity::class)->html();
        $this->assertStringContainsString('gx-server-bars', $html);
        $this->assertStringContainsString('<div class="gx-meter" style="display: grid; gap: .3rem; min-width: 0; column-gap: 2px;', $html);
        // Whole pixels, so every square is the same width, and the caption row is as wide as the squares
        $this->assertStringContainsString('round(down, calc((100% - 46px) / 24), 1px)', $html);
        $this->assertStringContainsString('grid-template-columns: subgrid;', $html);

        // The servers list keeps its 12px squares
        $column = view('filament.servers.meter', ['caption' => null, 'value' => '1/10', 'label' => 'Accounts', 'cells' => array_fill(0, 10, ['color' => null])])->render();
        $this->assertStringContainsString('max-width: 140px', $column);
    }

    public function test_needs_attention_lists_what_waits_and_only_what_the_admin_may_open(): void
    {
        $client = Client::factory()->create();
        Order::create(['client_id' => $client->id]);
        $this->notice(today()->subDays(3)->toDateString());
        ActivityLog::record('backup.failed', 'FTP upload failed');
        ActivityLog::create(['event' => 'backup.failed', 'description' => 'old', 'created_at' => now()->subDays(5)]);

        $this->actingAs(User::factory()->create());
        $titles = array_column(NeedsAttention::items(), 'title');
        $this->assertSame([
            '1 order waiting for Complete order',
            '1 overdue payment notice',
            'Backup failed',
        ], $titles);

        // Billing only: the notice, not the order or the activity log
        User::factory()->create();
        $this->actingAs(User::factory()->limitedTo([AdminArea::Billing])->create());
        $this->assertSame(['1 overdue payment notice'], array_column(NeedsAttention::items(), 'title'));
        $this->assertFalse(ServerCapacity::canView());
        $this->assertTrue(ActivityChart::canView());
    }

    public function test_needs_attention_lists_the_daily_tasks_and_leaves_out_what_is_fine(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-05 10:00'));
        $client = Client::factory()->create();

        Server::factory()->create(['name' => 'nl1', 'monitor_status' => MonitorStatus::Down, 'monitor_status_since' => now()->subHour()]);
        Server::factory()->create(['monitor_status' => MonitorStatus::Down, 'active' => false]);

        $hosting = Service::factory()->create(['client_id' => $client->id]);
        CancellationRequest::create(['service_id' => $hosting->id, 'client_id' => $client->id, 'type' => CancellationType::EndOfPeriod]);
        UpgradeRequest::create(['service_id' => $hosting->id, 'client_id' => $client->id, 'product_id' => Product::factory()->create()->id, 'price_cents' => 100]);

        Ticket::create(['client_id' => $client->id, 'subject' => 'Old', 'status' => TicketStatus::Open, 'last_reply_at' => now()->subHours(30)]);
        Ticket::create(['client_id' => $client->id, 'subject' => 'New', 'status' => TicketStatus::Open, 'last_reply_at' => now()->subHours(2)]);
        Ticket::create(['client_id' => $client->id, 'subject' => 'Theirs', 'status' => TicketStatus::Answered, 'last_reply_at' => now()->subDays(3)]);

        $this->notice(today()->toDateString());
        $this->notice(today()->addDay()->toDateString());

        // Expires at the registrar in 10 days; paid two years ahead of its expiry; fine; auto renew off; tracked only.
        $domain = fn (string $name, string $expires, string $due, array $service = [], string $type = 'register') => tap(
            Service::factory()->domain()->create(['domain' => $name, 'next_due_date' => $due, ...$service]),
            fn (Service $service) => $service->registration()->update(['expires_at' => $expires, 'registration_type' => $type]),
        );
        $domain('soon.gr', '2026-11-15', '2026-11-15');
        $domain('paid-ahead.gr', '2028-05-26', '2030-10-14');
        $domain('fine.gr', '2027-10-14', '2027-10-14');
        $domain('dropping.gr', '2026-11-10', '2026-11-10', ['auto_terminate' => true]);
        $domain('tracked.gr', '2026-11-10', '2026-11-10', type: 'external');

        app(Quotes::class)->create(['client_id' => $client->id, 'valid_until' => today()->addDays(2), 'items' => [[
            'type' => ServiceType::Other->value, 'label' => 'Care', 'billing_cycle' => BillingCycle::Annually->value, 'price_cents' => 100,
        ]]])->update(['status' => QuoteStatus::Sent]);
        app(Quotes::class)->create(['client_id' => $client->id, 'valid_until' => today()->addDays(20), 'items' => [[
            'type' => ServiceType::Other->value, 'label' => 'Later', 'billing_cycle' => BillingCycle::Annually->value, 'price_cents' => 100,
        ]]])->update(['status' => QuoteStatus::Sent]);

        $this->actingAs(User::factory()->create());
        $items = collect(NeedsAttention::items())->keyBy('title');

        $this->assertSame([
            '1 server down',
            '1 cancellation request to answer',
            '1 upgrade / downgrade request to answer',
            '1 ticket waiting for a reply over 24 hours',
            '1 payment notice due today',
            '2 domains to check at the registrar',
            '1 quote ending within 3 days',
        ], $items->keys()->all());
        $this->assertStringStartsWith('nl1 · ', $items['1 server down']['sub']);
        $this->assertSame('soon.gr, paid-ahead.gr', $items['2 domains to check at the registrar']['sub']);
        $this->assertFalse(NeedsAttention::isEmpty());
        $this->get(Dashboard::getUrl())->assertOk()->assertSee('gx-slot-NeedsAttention', false)->assertSee('1 server down');

        // Support only: the tickets, nothing else
        $this->actingAs(User::factory()->limitedTo([AdminArea::Support])->create());
        $this->assertSame(['1 ticket waiting for a reply over 24 hours'], array_column(NeedsAttention::items(), 'title'));
    }
}
