<?php

namespace Tests\Feature;

use App\Enums\AdminArea;
use App\Enums\ServiceStatus;
use App\Models\ActivityLog;
use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use App\Support\Health\SchedulerMonitor;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Plugins\SiteChecks\DnsStatus;
use Plugins\SiteChecks\SiteCheck;
use Plugins\SiteChecks\SiteCheckRunner;
use Plugins\SiteChecks\SiteChecksPage;
use Plugins\SiteChecks\SiteProbe;
use Plugins\SiteChecks\SslStatus;
use Tests\TestCase;

/** The Site checks plugin: each active hosting account's domain, DNS and HTTPS (Plugins\SiteChecks\SiteCheckRunner). */
class SiteChecksTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, mixed> 'a:host' => addresses, 'ns:host' => nameservers, 'cert:host' => certificate */
    private array $answers = [];

    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-23 05:00'));
        $this->app->instance(SiteProbe::class, new class($this->answers) extends SiteProbe
        {
            public function __construct(private array &$answers) {}

            public function addresses(string $host): array
            {
                return $this->answers["a:{$host}"] ?? [];
            }

            public function nameservers(string $host): array
            {
                return $this->answers["ns:{$host}"] ?? [];
            }

            public function certificate(string $host): array
            {
                return $this->answers["cert:{$host}"] ?? ['trusted' => false, 'valid_to' => null, 'issuer' => null, 'names' => [], 'error' => 'Connection refused'];
            }
        });

        $this->server = Server::factory()->create(['name' => 'pl1', 'ip_address' => '203.0.113.5', 'shared_ip' => null, 'hostname' => 'pl1.example.net']);
    }

    private function site(string $domain, array $attributes = []): Service
    {
        return Service::factory()->create($attributes + ['domain' => $domain, 'server_id' => $this->server->id]);
    }

    private function certificate(string $validTo, array $names, bool $trusted = true): array
    {
        return ['trusted' => $trusted, 'valid_to' => CarbonImmutable::parse($validTo), 'issuer' => "Let's Encrypt", 'names' => $names, 'error' => $trusted ? null : 'certificate verify failed'];
    }

    public function test_each_site_gets_its_dns_and_https_status(): void
    {
        $this->answers = [
            'a:good.gr' => ['203.0.113.5'], 'cert:good.gr' => $this->certificate('2027-01-10', ['good.gr', 'www.good.gr']),
            'a:soon.gr' => ['203.0.113.5'], 'cert:soon.gr' => $this->certificate('2026-11-01', ['soon.gr']),
            'a:old.gr' => ['203.0.113.5'], 'cert:old.gr' => $this->certificate('2026-10-01', ['old.gr']),
            'a:shop.good.gr' => ['203.0.113.5'], 'cert:shop.good.gr' => $this->certificate('2027-01-10', ['good.gr']),
            'a:self.gr' => ['203.0.113.5'], 'cert:self.gr' => $this->certificate('2027-01-10', ['self.gr'], trusted: false),
            'a:plain.gr' => ['203.0.113.5'],
            'a:moved.gr' => ['198.51.100.7'],
            'a:cf.gr' => ['104.16.1.1'], 'ns:cf.gr' => ['ada.ns.cloudflare.com'], 'cert:cf.gr' => $this->certificate('2027-01-10', ['*.gr', 'cf.gr']),
        ];
        foreach (['good.gr', 'soon.gr', 'old.gr', 'shop.good.gr', 'self.gr', 'plain.gr', 'moved.gr', 'cf.gr', 'gone.gr'] as $domain) {
            $this->site($domain);
        }
        $this->site('suspended.gr', ['status' => ServiceStatus::Suspended]);

        $summary = app(SiteCheckRunner::class)->run();
        $checks = SiteCheck::query()->get()->keyBy('domain');

        $this->assertSame(9, $summary['sites'], 'the suspended one is left out');
        $this->assertSame([DnsStatus::Ours, SslStatus::Valid], [$checks['good.gr']->dns_status, $checks['good.gr']->ssl_status]);
        $this->assertSame(SslStatus::Expiring, $checks['soon.gr']->ssl_status);
        $this->assertSame(SslStatus::Expired, $checks['old.gr']->ssl_status);
        $this->assertSame(SslStatus::WrongName, $checks['shop.good.gr']->ssl_status);
        $this->assertSame(SslStatus::Untrusted, $checks['self.gr']->ssl_status);
        $this->assertSame(SslStatus::None, $checks['plain.gr']->ssl_status);
        $this->assertSame('Connection refused', $checks['plain.gr']->ssl_error);
        $this->assertSame([DnsStatus::Elsewhere, SslStatus::Skipped], [$checks['moved.gr']->dns_status, $checks['moved.gr']->ssl_status]);
        $this->assertSame([DnsStatus::Cloudflare, SslStatus::Valid], [$checks['cf.gr']->dns_status, $checks['cf.gr']->ssl_status]);
        $this->assertSame(DnsStatus::None, $checks['gone.gr']->dns_status);
        $this->assertSame(7, $summary['problems']);
    }

    public function test_the_server_hostname_counts_as_its_address(): void
    {
        $this->server->update(['ip_address' => null]);
        $this->answers = ['a:pl1.example.net' => ['203.0.113.9'], 'a:good.gr' => ['203.0.113.9'], 'cert:good.gr' => $this->certificate('2027-01-10', ['good.gr'])];
        $this->site('good.gr');

        app(SiteCheckRunner::class)->run();

        $this->assertSame(DnsStatus::Ours, SiteCheck::query()->sole()->dns_status);
    }

    public function test_a_problem_rings_the_bell_once_and_again_when_it_comes_back(): void
    {
        $services = User::factory()->limitedTo([AdminArea::Services])->create();
        $billing = User::factory()->limitedTo([AdminArea::Billing])->create();
        $this->answers = ['a:soon.gr' => ['203.0.113.5'], 'cert:soon.gr' => $this->certificate('2026-11-01', ['soon.gr'])];
        $this->site('soon.gr');

        $this->artisan('gaiacp:check-sites')->expectsOutputToContain('soon.gr: certificate expires on 01/11/2026')->assertSuccessful();
        $this->artisan('gaiacp:check-sites')->assertSuccessful();

        $this->assertSame(1, ActivityLog::query()->where('event', 'site.problems')->count());
        $this->assertSame('1 new site problem(s)', $services->notifications()->sole()->data['title']);
        $this->assertStringEndsWith('/admin/site-checks', $services->notifications()->sole()->data['actions'][0]['url']);
        $this->assertSame(0, $billing->notifications()->count());

        // Renewed, then failing again: new again
        $this->answers['cert:soon.gr'] = $this->certificate('2027-01-30', ['soon.gr']);
        $this->artisan('gaiacp:check-sites')->assertSuccessful();
        $this->answers['cert:soon.gr'] = $this->certificate('2026-10-20', ['soon.gr']);
        $this->artisan('gaiacp:check-sites')->assertSuccessful();

        $this->assertSame(2, ActivityLog::query()->where('event', 'site.problems')->count());
    }

    public function test_sites_no_longer_active_are_dropped_and_the_plugin_switch_is_respected(): void
    {
        $service = $this->site('good.gr');
        app(SiteCheckRunner::class)->run();
        $this->assertSame(1, SiteCheck::query()->count());

        $service->update(['status' => ServiceStatus::Terminated]);
        app(SiteCheckRunner::class)->run();
        $this->assertSame(0, SiteCheck::query()->count());

        $service->update(['status' => ServiceStatus::Active]);
        DB::table('plugins')->updateOrInsert(['id' => 'site-checks'], ['enabled' => false, 'version' => '1.0.0']);
        $this->artisan('gaiacp:check-sites')->expectsOutputToContain('plugin is off')->assertSuccessful();
        $this->assertSame(0, SiteCheck::query()->count());

        $this->assertContains('gaiacp:check-sites', SchedulerMonitor::DAILY_COMMANDS);
    }

    public function test_the_page_lists_problems_and_checks_one_again(): void
    {
        $this->actingAs(User::factory()->create());
        $this->answers = [
            'a:good.gr' => ['203.0.113.5'], 'cert:good.gr' => $this->certificate('2027-01-10', ['good.gr']),
            'a:moved.gr' => ['198.51.100.7'],
        ];
        $this->site('good.gr');
        $this->site('moved.gr');
        app(SiteCheckRunner::class)->run();
        [$good, $moved] = [SiteCheck::query()->where('domain', 'good.gr')->sole(), SiteCheck::query()->where('domain', 'moved.gr')->sole()];

        $page = Livewire::test(SiteChecksPage::class)
            ->assertCanSeeTableRecords([$moved])
            ->assertCanNotSeeTableRecords([$good])
            ->assertSee(__('Points elsewhere'))
            ->assertSee('198.51.100.7');

        $this->answers['a:moved.gr'] = ['203.0.113.5'];
        $this->answers['cert:moved.gr'] = $this->certificate('2027-01-10', ['moved.gr']);
        $page->callAction(TestAction::make('checkNow')->table($moved))->assertNotified('moved.gr');

        $this->assertSame(DnsStatus::Ours, $moved->fresh()->dns_status);
    }

    public function test_wildcards_cover_one_level_only(): void
    {
        $this->assertTrue(SiteCheckRunner::covers(['*.good.gr'], 'shop.good.gr'));
        $this->assertFalse(SiteCheckRunner::covers(['*.good.gr'], 'a.shop.good.gr'));
        $this->assertFalse(SiteCheckRunner::covers(['*.good.gr'], 'good.gr'));
    }
}
