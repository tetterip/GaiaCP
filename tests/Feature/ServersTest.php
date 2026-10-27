<?php

namespace Tests\Feature;

use App\Enums\BillingCycle;
use App\Enums\ServiceStatus;
use App\Filament\Clusters\Reports\Pages\IncomeForecast;
use App\Filament\Clusters\Reports\Pages\ServerRevenue;
use App\Filament\Resources\Orders\Pages\CreateOrder;
use App\Filament\Resources\Servers\Pages\CreateServer;
use App\Filament\Resources\Servers\Pages\EditServer;
use App\Filament\Resources\Servers\Pages\ListServers;
use App\Models\ActivityLog;
use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use App\Reports\IncomeForecast as Forecast;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\FakesHestia;
use Tests\FakesPlesk;
use Tests\FakesWhm;
use Tests\TestCase;

/** Servers: capacity, monthly cost, IP, status page, the admin's control panel login, and profit in the reports. */
class ServersTest extends TestCase
{
    use FakesHestia, FakesPlesk, FakesWhm, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    private function accounts(Server $server, int $count, array $attributes = []): void
    {
        Service::factory()->count($count)->create(['server_id' => $server->id, ...$attributes]);
    }

    public function test_a_server_keeps_its_capacity_cost_ip_and_status_page(): void
    {
        Livewire::test(CreateServer::class)
            ->fillForm([
                'name' => 'pl1', 'type' => 'plesk', 'hostname' => 'pl1.example.com', 'port' => 8443, 'username' => 'admin', 'api_token' => 'secret',
                'max_accounts' => 120, 'monthly_cost_cents' => '45,50', 'ip_address' => '192.0.2.10', 'status_url' => 'https://status.example.com',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $server = Server::sole();
        $this->assertSame(120, $server->max_accounts);
        $this->assertSame(4550, $server->monthly_cost_cents);
        $this->assertSame('192.0.2.10', $server->ip_address);
        $this->assertSame('https://status.example.com', $server->status_url);

        Livewire::test(CreateServer::class)
            ->fillForm(['name' => 'x', 'type' => 'plesk', 'hostname' => 'x.example.com', 'port' => 8443, 'ip_address' => 'nope', 'status_url' => 'not a url', 'monthly_cost_cents' => '-3'])
            ->call('create')
            ->assertHasFormErrors(['ip_address', 'status_url', 'monthly_cost_cents']);
    }

    public function test_accounts_count_active_and_suspended_hosting_against_the_maximum(): void
    {
        $server = Server::factory()->create(['max_accounts' => 120]);
        $this->accounts($server, 32);
        $this->accounts($server, 1, ['status' => ServiceStatus::Suspended]);
        $this->accounts($server, 2, ['status' => ServiceStatus::Terminated]);
        $this->accounts($server, 1, ['status' => ServiceStatus::Pending]);
        Service::factory()->domain()->create(['server_id' => $server->id]);

        $this->assertSame(33, $server->accountsUsed());
        $this->assertSame('33/120 (28%)', $server->accountsText());
        $this->assertSame($server->name.' · accounts: 33/120 (28%)', $server->optionLabel());

        $unlimited = Server::factory()->create(['max_accounts' => null]);
        $this->accounts($unlimited, 4);
        $this->assertNull($unlimited->accountsShare());
        $this->assertSame('4', $unlimited->accountsText());
    }

    public function test_the_edit_page_never_shows_the_stored_token_and_turns_off_autofill(): void
    {
        $server = Server::factory()->create(['username' => 'reseller', 'api_token' => 'stored-secret-token']);

        $html = Livewire::test(EditServer::class, ['record' => $server->getRouteKey()])->html();

        $this->assertStringNotContainsString('stored-secret-token', $html);
        // The browser's saved login would otherwise fill the empty token field and save it over the real one.
        $this->assertMatchesRegularExpression('/<input[^>]*autocomplete="new-password"[^>]*type="password"|<input[^>]*type="password"[^>]*autocomplete="new-password"/s', $html);
    }

    public function test_the_list_shows_the_accounts_bar_ip_and_panel_links(): void
    {
        $full = Server::factory()->create(['name' => 'Full', 'max_accounts' => 120, 'ip_address' => '192.0.2.10', 'status_url' => 'https://status.example.com']);
        $this->accounts($full, 33);
        $open = Server::factory()->create(['name' => 'Open', 'max_accounts' => null]);
        $this->accounts($open, 4);

        Livewire::test(ListServers::class)
            ->assertSee('33/120 (28%)')
            ->assertSee('aria-label="Accounts: 33/120 (28%)"', false)
            ->assertSee('192.0.2.10')
            ->assertTableActionVisible('panelLogin', $full)
            ->assertTableActionVisible('statusPage', $full)
            ->assertTableActionHidden('statusPage', $open)
            ->assertTableActionHasUrl('panelLogin', route('filament.admin.server-panel-login', ['server' => $full->id]), $full)
            ->assertTableActionHasUrl('statusPage', 'https://status.example.com', $full);

        // One bar only: the server without a maximum shows its count alone.
        $this->get(ListServers::getUrl())->assertOk()->assertSee('33/120 (28%)');
        $this->assertSame(1, substr_count(Livewire::test(ListServers::class)->html(), 'aria-label="Accounts: '));
    }

    public function test_the_admin_opens_whm_signed_in(): void
    {
        $this->fakeWhm();
        $server = Server::factory()->create(['username' => 'root']);

        $this->get(route('filament.admin.server-panel-login', ['server' => $server->id]))
            ->assertRedirect('https://whm.test:2087/cpsess456/login/?session=root:token');

        $this->assertSame([['create_user_session', ['user' => 'root', 'service' => 'whostmgrd']]], $this->whmCalls);
        $log = ActivityLog::where('event', 'server.panel_login')->sole();
        $this->assertStringNotContainsString('cpsess', $log->description);
    }

    public function test_the_admin_opens_plesk_signed_in(): void
    {
        $this->fakePlesk();
        $server = Server::factory()->plesk()->create(['hostname' => 'pl1.example.com', 'username' => null]);

        $this->get(route('filament.admin.server-panel-login', ['server' => $server->id]))
            ->assertRedirect('https://pl1.example.com:8443/enterprise/rsession_init.php?PLESKSESSID=sess-127.0.0.1-admin');
    }

    public function test_hestiacp_opens_its_login_page(): void
    {
        $this->fakeHestia();
        $server = Server::factory()->hestia()->create(['hostname' => 'cp.example.com']);

        $this->get(route('filament.admin.server-panel-login', ['server' => $server->id]))
            ->assertRedirect('https://cp.example.com:8083/login/');
    }

    public function test_a_failed_panel_login_goes_back_to_the_list(): void
    {
        $this->fakeWhm();
        $this->whmFailures['create_user_session'] = 'Access denied';
        $server = Server::factory()->create();

        $this->get(route('filament.admin.server-panel-login', ['server' => $server->id]))
            ->assertRedirect(ListServers::getUrl());

        $this->assertSame('server.panel_login', ActivityLog::where('event', 'provisioning.failed')->sole()->properties['event']);
    }

    public function test_only_admins_who_open_servers_get_a_panel_login(): void
    {
        $server = Server::factory()->create();

        $this->actingAs(User::factory()->limitedTo()->create())
            ->get(route('filament.admin.server-panel-login', ['server' => $server->id]))
            ->assertForbidden();
    }

    public function test_new_order_shows_each_servers_accounts(): void
    {
        $server = Server::factory()->create(['name' => 'pl1', 'max_accounts' => 10]);
        $this->accounts($server, 4);

        Livewire::test(CreateOrder::class)
            ->fillForm(['items' => [['type' => 'hosting']]])
            ->assertSee('pl1 · accounts: 4/10 (40%)');
    }

    public function test_reports_show_profit_after_server_costs(): void
    {
        $this->travelTo('2026-10-10 12:00');
        $server = Server::factory()->create(['name' => 'pl1', 'monthly_cost_cents' => 3000]);
        // 10€ a month from 15/10: 1000 every month.
        Service::factory()->create(['server_id' => $server->id, 'price_cents' => 1000, 'billing_cycle' => BillingCycle::Monthly, 'start_date' => '2026-09-15', 'next_due_date' => '2026-10-15']);
        // Active with a cost and no accounts: still in Server revenue. Inactive: costs nothing.
        Server::factory()->create(['name' => 'empty', 'monthly_cost_cents' => 500]);
        Server::factory()->create(['name' => 'old', 'monthly_cost_cents' => 9900, 'active' => false]);

        $forecast = new Forecast;
        $this->assertSame(3500, $forecast->serverCosts());

        $servers = $forecast->byServer(12)->keyBy('server');
        $this->assertSame(['pl1', 'empty'], $servers->keys()->all());
        $this->assertSame(3000, $servers['pl1']['cost_cents']);
        $this->assertSame(0, $servers['empty']['accounts']);

        $income = Livewire::test(IncomeForecast::class);
        $income->assertSee('Profit')->assertSee('minus €35.00, what the active servers cost a month');
        $rows = $income->instance()->report();
        $this->assertSame('-€25.00', last($rows['rows'][0]['cells']));
        $this->assertSame('-€300.00', last($rows['totals'])); // 12 × 10€ − 12 × 35€

        $revenue = Livewire::test(ServerRevenue::class)->assertSee('profit = per month minus it');
        $byName = collect($revenue->instance()->report()['rows'])->keyBy(fn (array $r) => $r['cells'][0]);
        $this->assertSame(['€30.00', '-€20.00'], array_slice($byName['pl1']['cells'], -2));
        $this->assertSame(['€5.00', '-€5.00'], array_slice($byName['empty']['cells'], -2));
    }

    public function test_reports_leave_profit_out_without_costs(): void
    {
        Service::factory()->create();

        Livewire::test(IncomeForecast::class)->assertDontSee('Profit');
        Livewire::test(ServerRevenue::class)->assertDontSee('profit = per month minus it');
    }
}
