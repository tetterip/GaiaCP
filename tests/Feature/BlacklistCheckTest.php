<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Plugins\Blacklists\BlacklistCheck;
use Plugins\Blacklists\BlacklistListing;
use Plugins\Blacklists\BlacklistsSettings;
use Plugins\Blacklists\DnsResolver;
use Tests\TestCase;

/** The IP blacklist check plugin: servers' IPs in DNS blacklists, listings in the bell. */
class BlacklistCheckTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, list<string>> host => A records */
    private array $answers = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['gaiacp.blacklists.zones' => "zen.spamhaus.org\nbl.spamcop.net"]);
        $this->app->instance(DnsResolver::class, new class($this->answers) extends DnsResolver
        {
            public function __construct(private array &$answers) {}

            public function a(string $host): array
            {
                return $this->answers[$host] ?? [];
            }

            public function addresses(string $host): array
            {
                return $this->answers['addresses:'.$host] ?? [];
            }
        });
    }

    public function test_reverses_ipv4_and_ipv6(): void
    {
        $this->assertSame('4.3.2.1', BlacklistCheck::reverse('1.2.3.4'));
        $this->assertSame('1.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.8.b.d.0.1.0.0.2', BlacklistCheck::reverse('2001:db8::1'));
    }

    public function test_a_listing_is_noted_once_and_its_removal_too(): void
    {
        $admin = User::factory()->create();
        $server = Server::factory()->create(['name' => 'pl1', 'ip_address' => '93.184.216.34']);
        $this->answers['34.216.184.93.zen.spamhaus.org'] = ['127.0.0.2'];

        $summary = app(BlacklistCheck::class)->run();
        app(BlacklistCheck::class)->run(); // again: nothing new

        $this->assertSame(1, $summary['listed']);
        $this->assertSame(1, BlacklistListing::query()->count());
        $this->assertSame(1, ActivityLog::query()->where('event', 'blacklist.listed')->count());
        $this->assertStringContainsString('pl1 is on a spam blacklist', $admin->notifications()->first()->data['title']);

        $this->answers = [];
        app(BlacklistCheck::class)->run();

        $this->assertSame(0, BlacklistListing::query()->count());
        $this->assertSame(1, ActivityLog::query()->where('event', 'blacklist.delisted')->where('properties->server_id', $server->id)->count());
    }

    public function test_a_refused_question_is_not_a_listing_and_keeps_what_was_known(): void
    {
        $server = Server::factory()->create(['ip_address' => '93.184.216.34']);
        BlacklistListing::query()->create(['server_id' => $server->id, 'ip' => '93.184.216.34', 'zone' => 'zen.spamhaus.org', 'answer' => '127.0.0.2', 'listed_at' => now(), 'checked_at' => now()]);
        $this->answers['34.216.184.93.zen.spamhaus.org'] = ['127.255.255.254']; // Spamhaus: public resolver refused

        $summary = app(BlacklistCheck::class)->run();

        $this->assertSame(['zen.spamhaus.org'], $summary['refused']);
        $this->assertSame(1, BlacklistListing::query()->count());
        $this->assertSame(0, ActivityLog::query()->where('event', 'blacklist.delisted')->count());
    }

    public function test_without_an_ip_the_hostname_is_used_and_private_addresses_skipped(): void
    {
        $server = Server::factory()->create(['ip_address' => null, 'hostname' => 'pl1.example.com']);
        $this->answers['addresses:pl1.example.com'] = ['10.0.0.5', '93.184.216.34'];

        $this->assertSame(['93.184.216.34'], app(BlacklistCheck::class)->ips($server));
    }

    public function test_the_settings_page_checks_now_and_shows_the_listings(): void
    {
        $this->actingAs(User::factory()->create());
        Server::factory()->create(['name' => 'nl1', 'ip_address' => '93.184.216.34']);
        $this->answers['34.216.184.93.bl.spamcop.net'] = ['127.0.0.2'];

        Livewire::test(BlacklistsSettings::class)
            ->assertSee('No server is listed.')
            ->callAction('checkNow')
            ->assertNotified('1 listing(s) found');

        Livewire::test(BlacklistsSettings::class)->assertSee('nl1 (93.184.216.34) on bl.spamcop.net');
        $this->artisan('gaiacp:check-blacklists')->assertSuccessful();
    }
}
