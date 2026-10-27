<?php

namespace Tests\Feature;

use App\Actions\ServiceLifecycle;
use App\Enums\ServiceStatus;
use App\Enums\UserRole;
use App\Filament\Client\Pages\Help;
use App\Filament\Client\Resources\Services\Pages\ViewService;
use App\Filament\Resources\Services\Pages\EditService;
use App\Livewire\HostingUsage;
use App\Models\Client;
use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use App\Provisioning\ProvisioningException;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\FakesPlesk;
use Tests\FakesWhm;
use Tests\TestCase;

/** Disk space and bandwidth of a hosting account, read from cPanel / Plesk and shown to the admin and the client. */
class HostingUsageTest extends TestCase
{
    use FakesPlesk, FakesWhm, RefreshDatabase;

    private Client $client;

    private Service $hosting;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = Client::factory()->create();
        $this->hosting = Service::factory()->for($this->client)->create(['username' => 'maria', 'domain' => 'maria.gr']);
    }

    private function lifecycle(): ServiceLifecycle
    {
        return app(ServiceLifecycle::class);
    }

    public function test_cpanel_usage_comes_from_accountsummary_and_showbw(): void
    {
        $this->fakeWhm();
        $this->whmAccounts['maria'] = false;
        $this->whmUsage['maria'] = ['diskused' => '150M', 'disklimit' => '2G', 'totalbytes' => 3_221_225_472, 'limit' => 'unlimited', 'stats' => [
            ['name' => 'emailaccounts', 'count' => '3', '_count' => 3, 'max' => '10', '_max' => 10],
            ['name' => 'sqldatabases', 'count' => '1', '_count' => 1, 'max' => '∞', '_max' => 'unlimited'],
            ['name' => 'mysqldiskusage', 'count' => '64', '_count' => '64.00', 'max' => '∞', '_max' => null, 'units' => 'MB'],
            ['name' => 'addondomains', 'count' => '0', '_count' => 0, 'max' => '0', '_max' => 0],
            ['name' => 'bandwidthusage', 'count' => '1', '_count' => 1],
        ]];

        $usage = $this->lifecycle()->usage($this->hosting);

        $this->assertSame(150 * 1024 ** 2, $usage->diskUsed);
        $this->assertSame(2 * 1024 ** 3, $usage->diskLimit);
        $this->assertSame(3_221_225_472, $usage->bandwidthUsed);
        $this->assertNull($usage->bandwidthLimit);
        $this->assertNotNull($usage->readAt);
        $this->assertSame(['email_accounts' => [3, 10], 'databases' => [1, null], 'database_disk' => [64 * 1024 ** 2, null], 'addon_domains' => [0, 0]], $usage->counts);
        $this->assertSame(['accountsummary', 'showbw', 'uapi_cpanel'], $this->whmFunctions());
        $this->assertSame('^maria$', $this->whmCalls[1][1]['search']);
        $this->assertSame(['cpanel_user' => 'maria', 'cpanel_module' => 'StatsBar', 'cpanel_function' => 'get_stats'], array_intersect_key($this->whmCalls[2][1], array_flip(['cpanel_user', 'cpanel_module', 'cpanel_function'])));
    }

    public function test_cpanel_usage_without_the_statsbar(): void
    {
        $this->fakeWhm();
        $this->whmAccounts['maria'] = false;
        $this->whmFailures['uapi_cpanel'] = 'Permission denied';

        $usage = $this->lifecycle()->usage($this->hosting);

        $this->assertSame(0, $usage->diskUsed);
        $this->assertSame([], $usage->counts);
    }

    public function test_cpanel_unlimited_disk_and_a_missing_account(): void
    {
        $this->fakeWhm();
        $this->whmAccounts['maria'] = false;
        $this->whmUsage['maria'] = ['diskused' => '0M', 'disklimit' => 'unlimited', 'totalbytes' => 0, 'limit' => 10_737_418_240];

        $usage = $this->lifecycle()->usage($this->hosting);

        $this->assertSame(0, $usage->diskUsed);
        $this->assertNull($usage->diskLimit);
        $this->assertSame(10_737_418_240, $usage->bandwidthLimit);

        $other = Service::factory()->for($this->client)->create(['username' => 'nobody']);
        $this->expectException(ProvisioningException::class);
        $this->lifecycle()->usage($other);
    }

    public function test_plesk_usage_comes_from_the_subscription(): void
    {
        $this->fakePlesk();
        $this->hosting->update(['server_id' => Server::factory()->plesk()->create()->id]);
        $this->pleskWebspaces['maria.gr'] = ['owner' => 12, 'status' => 0, 'plan' => 'Starter', 'ftp_login' => 'maria', 'ip' => '203.0.113.5',
            'real_size' => 524_288_000, 'traffic' => 1_048_576, 'disk_space' => 1_073_741_824, 'max_traffic' => -1,
            'box' => 2, 'max_box' => 5, 'db' => 1, 'dbases' => 2_097_152];

        $usage = $this->lifecycle()->usage($this->hosting->fresh());

        $this->assertSame(524_288_000, $usage->diskUsed);
        $this->assertSame(1_073_741_824, $usage->diskLimit);
        $this->assertSame(1_048_576, $usage->bandwidthUsed);
        $this->assertNull($usage->bandwidthLimit);
        $this->assertSame(['email_accounts' => [2, 5], 'databases' => [1, null], 'sites' => [1, null], 'subdomains' => [0, null], 'database_disk' => [2_097_152, null]], $usage->counts);

        // Datasets in the schema's order.
        $datasets = array_map(fn ($node) => $node->getName(), iterator_to_array($this->pleskCalls[0][1]->dataset->children(), false));
        $this->assertSame(['gen_info', 'limits', 'stat', 'disk_usage'], $datasets);
    }

    public function test_a_reading_is_kept_until_read_again(): void
    {
        $this->fakeWhm();
        $this->whmAccounts['maria'] = false;
        $this->whmUsage['maria'] = ['diskused' => '150M'];

        $this->lifecycle()->usage($this->hosting);
        $this->whmUsage['maria'] = ['diskused' => '300M'];

        $this->assertSame(150 * 1024 ** 2, $this->lifecycle()->usage($this->hosting)->diskUsed);
        $this->assertSame(300 * 1024 ** 2, $this->lifecycle()->usage($this->hosting, fresh: true)->diskUsed);
        $this->assertCount(6, $this->whmCalls);
        // No objects in the cache: production's unserializes none.
        $this->assertIsArray(Cache::get("service-usage:{$this->hosting->id}"));
    }

    public function test_only_active_or_suspended_hosting_on_a_server_has_usage(): void
    {
        $this->hosting->update(['status' => ServiceStatus::Pending]);

        $this->assertFalse(HostingUsage::hasUsage($this->hosting));
        $this->expectException(ProvisioningException::class);
        $this->lifecycle()->usage($this->hosting);
    }

    public function test_the_admin_sees_usage_on_the_service_and_reads_it_again(): void
    {
        $this->fakeWhm();
        $this->whmAccounts['maria'] = false;
        $this->whmUsage['maria'] = ['diskused' => '150M', 'disklimit' => '2G', 'totalbytes' => 1_073_741_824, 'stats' => [
            ['name' => 'emailaccounts', '_count' => 3, '_max' => 10],
        ]];
        $this->actingAs(User::factory()->create());

        Livewire::test(EditService::class, ['record' => $this->hosting->id])
            ->assertSee('Usage');

        Livewire::test(HostingUsage::class, ['service' => $this->hosting])
            ->assertSee('Disk space')
            ->assertSeeInOrder(['7%', 'Disk space', '150 MB', 'of 2 GB'])
            ->assertSeeInOrder(['∞', 'Bandwidth this month', '1 GB', 'Unlimited'])
            ->assertSeeInOrder(['Email accounts', '3', 'of 10'])
            ->assertSee('Read again')
            ->tap(fn () => $this->whmUsage['maria']['diskused'] = '1900M')
            ->call('refreshUsage')
            ->assertSeeInOrder(['93%', 'Disk space', '1.9 GB'])
            ->assertSee('var(--warning-500)', escape: false);
    }

    public function test_the_admin_sees_the_panels_error(): void
    {
        $this->fakeWhm();
        $this->whmAccounts['maria'] = false;
        $this->whmFailures['accountsummary'] = 'Access denied';
        $this->actingAs(User::factory()->create());

        Livewire::test(HostingUsage::class, ['service' => $this->hosting])
            ->assertSee('Could not read usage: WHM accountsummary: Access denied');
    }

    public function test_no_usage_on_a_pending_service_page(): void
    {
        $this->fakeWhm();
        $this->hosting->update(['status' => ServiceStatus::Pending]);
        $this->actingAs(User::factory()->create());

        Livewire::test(EditService::class, ['record' => $this->hosting->id])
            ->assertDontSee('Read from the panel; kept for 15 minutes.');
    }

    public function test_the_client_sees_usage_unless_it_is_switched_off(): void
    {
        $this->fakeWhm();
        $this->whmAccounts['maria'] = false;
        $this->whmUsage['maria'] = ['diskused' => '150M', 'disklimit' => '2G'];
        $user = User::factory()->create(['role' => UserRole::Client, 'client_id' => $this->client->id, 'email' => $this->client->email]);
        Filament::setCurrentPanel('client');
        $this->actingAs($user, 'client');

        Livewire::test(ViewService::class, ['record' => $this->hosting->getRouteKey()])
            ->assertSee('Usage');

        Livewire::test(HostingUsage::class, ['service' => $this->hosting, 'client' => true])
            ->assertSeeInOrder(['7%', 'Disk space', '150 MB', 'of 2 GB'])
            ->assertDontSee('Read again')
            ->call('refreshUsage')
            ->assertForbidden();

        $this->assertTrue(Help::features()['hosting_usage']);

        config(['gaiacp.client_area.hosting_usage' => false]);

        Livewire::test(ViewService::class, ['record' => $this->hosting->getRouteKey()])
            ->assertDontSee('Usage');
        $this->assertFalse(Help::features()['hosting_usage']);
    }

    public function test_the_client_gets_a_plain_message_when_the_panel_fails(): void
    {
        $this->fakeWhm();
        $this->whmFailures['accountsummary'] = 'Access denied';

        Livewire::test(HostingUsage::class, ['service' => $this->hosting, 'client' => true])
            ->assertSee('Please try again later.')
            ->assertDontSee('Access denied');
    }
}
