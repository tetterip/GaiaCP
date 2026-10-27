<?php

namespace Tests\Feature;

use App\Actions\CompareServerAccounts;
use App\Actions\FixServerDifference;
use App\Enums\ServiceStatus;
use App\Filament\Resources\Servers\Pages\CompareServer;
use App\Filament\Resources\Servers\Pages\EditServer;
use App\Filament\Resources\Servers\ServerResource;
use App\Filament\Resources\Services\Pages\CreateService;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use App\Provisioning\PanelAccount;
use App\Provisioning\ProvisionerFactory;
use App\Support\Health\SchedulerMonitor;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\FakesDirectAdmin;
use Tests\FakesHestia;
use Tests\FakesPlesk;
use Tests\FakesWhm;
use Tests\TestCase;

/** Servers → Compare with the server: the panel's accounts against GaiaCP's hosting (App\Actions\CompareServerAccounts). */
class CompareServerAccountsTest extends TestCase
{
    use FakesDirectAdmin, FakesHestia, FakesPlesk, FakesWhm, RefreshDatabase;

    private Server $server;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
        $this->server = Server::factory()->create(['name' => 'nl1']);
        $this->client = Client::factory()->create(['first_name' => 'Maria', 'last_name' => 'Papa']);
    }

    private function hosting(array $attributes): Service
    {
        return Service::factory()->for($this->client)->create($attributes + ['server_id' => $this->server->id, 'package' => 'basic']);
    }

    public function test_cpanel_accounts_are_sorted_into_only_on_the_server_missing_and_different(): void
    {
        $this->fakeWhm();
        $this->whmAccounts = ['maria' => false, 'nikos' => true, 'oldsite' => false, 'stray' => false, 'bigger' => false];
        $this->whmDomains = ['maria' => 'maria.gr', 'nikos' => 'nikos.gr', 'oldsite' => 'old.gr', 'stray' => 'stray.gr', 'bigger' => 'bigger.gr'];
        $this->whmPlans = ['bigger' => 'pro'];

        $this->hosting(['username' => 'maria', 'domain' => 'maria.gr']); // matching
        $nikos = $this->hosting(['username' => 'nikos', 'domain' => 'nikos.gr']); // suspended on the server only
        $bigger = $this->hosting(['username' => 'bigger', 'domain' => 'bigger.gr']); // another package
        $gone = $this->hosting(['username' => 'gone', 'domain' => 'gone.gr']); // missing on the server
        $old = $this->hosting(['username' => 'oldsite', 'domain' => 'old.gr', 'status' => ServiceStatus::Terminated]);
        $this->hosting(['username' => 'later', 'status' => ServiceStatus::Pending]); // not set up yet: left out
        Service::factory()->for($this->client)->create(['username' => 'elsewhere']); // on another server

        $result = app(CompareServerAccounts::class)->handle($this->server);

        $this->assertSame(1, $result['matching']);
        $this->assertEqualsCanonicalizing(['oldsite', 'stray'], array_map(fn ($row) => $row['account']->username, $result['onServerOnly']));
        $former = collect($result['onServerOnly'])->firstWhere('account.username', 'oldsite')['former'];
        $this->assertTrue($former->is($old), 'still on the server although terminated in GaiaCP');
        $this->assertSame([$gone->id], array_map(fn (Service $s) => $s->id, $result['missing']));

        $different = collect($result['different'])->mapWithKeys(fn ($row) => [$row['service']->id => $row['differences']]);
        $this->assertSame(['Suspended on the server, active in GaiaCP'], $different[$nikos->id]);
        $this->assertSame(['Package'], $different[$bigger->id]);
    }

    public function test_a_plesk_subscription_without_a_username_matches_by_domain(): void
    {
        $this->fakePlesk();
        $this->server->update(Server::factory()->plesk()->raw());
        $this->pleskWebspaces = [
            'maria.gr' => ['owner' => 1, 'status' => 0, 'plan' => 'Starter', 'ftp_login' => 'mariaftp', 'ip' => '203.0.113.5'],
            'shop.gr' => ['owner' => 1, 'status' => 16, 'plan' => 'Business', 'ftp_login' => 'shop', 'ip' => '203.0.113.5'],
        ];

        $this->assertEquals([
            new PanelAccount('mariaftp', 'maria.gr', 'Starter', false),
            new PanelAccount('shop', 'shop.gr', 'Business', true),
        ], app(ProvisionerFactory::class)->forServer($this->server->fresh())->accounts());

        $this->hosting(['username' => null, 'domain' => 'maria.gr', 'package' => 'Starter']);
        $result = app(CompareServerAccounts::class)->handle($this->server->fresh());

        $this->assertSame(1, $result['matching']);
        $this->assertSame('shop', $result['onServerOnly'][0]['account']->username);
    }

    public function test_hestia_and_directadmin_list_their_accounts(): void
    {
        $this->fakeHestia();
        $this->hestiaUsers = ['maria' => ['PACKAGE' => 'default', 'SUSPENDED' => 'yes', 'domains' => ['maria.gr']]];
        $hestia = Server::factory()->hestia()->create();

        $this->assertEquals([new PanelAccount('maria', 'maria.gr', 'default', true)], app(ProvisionerFactory::class)->forServer($hestia)->accounts());

        $this->fakeDirectAdmin();
        $this->daUsers = ['nikos' => ['domain' => 'nikos.gr', 'package' => 'pro', 'suspended' => 'no']];
        $da = Server::factory()->directAdmin()->create();

        $this->assertEquals([new PanelAccount('nikos', 'nikos.gr', 'pro', false)], app(ProvisionerFactory::class)->forServer($da)->accounts());
    }

    public function test_the_page_shows_the_comparison_and_a_failure(): void
    {
        $this->fakeWhm();
        $this->whmAccounts = ['stray' => false];
        $this->whmDomains = ['stray' => 'stray.gr'];
        $this->hosting(['username' => 'gone', 'label' => 'Gone hosting', 'domain' => 'gone.gr']);

        Livewire::test(EditServer::class, ['record' => $this->server->id])
            ->assertActionVisible('compare');

        Livewire::test(CompareServer::class, ['record' => $this->server->id])
            ->assertSee(__('Compare :server with GaiaCP', ['server' => 'nl1']))
            ->assertSee('stray.gr')
            ->assertSee('Gone hosting')
            ->assertSee('Maria Papa');

        $this->whmFailures['listaccts'] = 'Access denied';
        Livewire::test(CompareServer::class, ['record' => $this->server->id])
            ->assertSee(__('The server could not be read'))
            ->assertSee('Access denied');
    }

    public function test_accounts_billed_on_another_server_or_ignored_are_not_problems(): void
    {
        $this->fakeWhm();
        $this->whmAccounts = ['moved' => true, 'reseller' => false, 'leftover' => true, 'stray' => false];
        $this->whmDomains = ['moved' => 'moved.gr', 'reseller' => 'reseller.gr', 'leftover' => 'left.gr', 'stray' => 'stray.gr'];
        $other = Server::factory()->create(['name' => 'pl1']);
        $moved = Service::factory()->for($this->client)->create(['server_id' => $other->id, 'username' => 'moved2', 'domain' => 'moved.gr']);
        $this->server->forceFill(['ignored_accounts' => ['reseller' => 'our reseller account']])->save();

        $result = app(CompareServerAccounts::class)->handle($this->server->fresh());
        $rows = collect($result['onServerOnly'])->keyBy('account.username');

        $this->assertTrue($rows['moved']['elsewhere']->is($moved), 'same domain on pl1');
        $this->assertTrue($rows['reseller']['ignored']);
        $this->assertSame('our reseller account', $rows['reseller']['note']);
        $this->assertSame(['only:stray'], array_keys(CompareServerAccounts::problems($result)), 'the suspended leftover is not one either');

        // The page lists them apart
        Livewire::test(CompareServer::class, ['record' => $this->server->id])
            ->assertSet('comparison.onServerOnly.unbilled.0.username', 'stray')
            ->assertSet('comparison.onServerOnly.suspended.0.username', 'leftover')
            ->assertSet('comparison.onServerOnly.elsewhere.0.elsewhere.server', 'pl1')
            ->assertSet('comparison.onServerOnly.ignored.0.username', 'reseller')
            ->assertSee(__('Billed on another server'));
    }

    public function test_an_account_is_marked_not_billed_on_purpose_and_shown_again(): void
    {
        $this->fakeWhm();
        $this->whmAccounts = ['reseller' => false];
        $this->whmDomains = ['reseller' => 'reseller.gr'];

        $page = Livewire::test(CompareServer::class, ['record' => $this->server->id])
            ->callAction(TestAction::make('ignore')->arguments(['username' => 'reseller']), ['note' => 'our reseller account'])
            ->assertHasNoActionErrors();

        $this->assertSame(['reseller' => 'our reseller account'], $this->server->fresh()->ignored_accounts);
        $page->assertSet('comparison.onServerOnly.ignored.0.username', 'reseller')
            ->assertSee('our reseller account')
            ->callAction(TestAction::make('unignore')->arguments(['username' => 'reseller']))
            ->assertSet('comparison.onServerOnly.unbilled.0.username', 'reseller');

        $this->assertNull($this->server->fresh()->ignored_accounts);
        $this->assertSame(2, ActivityLog::query()->whereIn('event', ['server.account_ignored', 'server.account_unignored'])->count());
    }

    public function test_add_to_gaiacp_opens_a_new_service_filled_in_from_the_account(): void
    {
        $this->fakeWhm();
        Livewire::withQueryParams(['server' => $this->server->id, 'username' => 'stray', 'domain' => 'stray.gr', 'package' => 'basic', 'suspended' => 1])
            ->test(CreateService::class)
            ->assertSet('data.server_id', $this->server->id)
            ->assertSet('data.username', 'stray')
            ->assertSet('data.domain', 'stray.gr')
            ->assertSet('data.status', ServiceStatus::Suspended->value)
            ->set('data.client_id', $this->client->id)
            ->set('data.price_cents', '60')
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertRedirect(ServerResource::getUrl('compare', ['record' => $this->server]));

        $service = Service::query()->where('username', 'stray')->sole();
        $this->assertSame(ServiceStatus::Suspended, $service->status);
        $this->assertSame(6000, $service->price_cents);
    }

    public function test_the_nightly_run_tells_the_super_admins_only_what_is_new(): void
    {
        $this->fakeWhm();
        $this->whmAccounts = ['stray' => false];
        $this->whmDomains = ['stray' => 'stray.gr'];
        auth()->logout();
        $super = User::query()->sole();

        $this->artisan('gaiacp:compare-servers')->assertSuccessful();
        $this->assertSame(1, ActivityLog::query()->where('event', 'server.accounts_differ')->count());
        $this->assertSame('nl1: 1 new difference(s) with GaiaCP', $super->notifications()->sole()->data['title']);

        // Nothing new: quiet
        $this->artisan('gaiacp:compare-servers')->assertSuccessful();
        $this->assertSame(1, ActivityLog::query()->where('event', 'server.accounts_differ')->count());

        // A new one, and a dry run that tells nobody
        $this->whmAccounts['other'] = false;
        $this->artisan('gaiacp:compare-servers --dry-run')->expectsOutputToContain('other (other.example) is on the server but not billed')->assertSuccessful();
        $this->assertSame(1, ActivityLog::query()->where('event', 'server.accounts_differ')->count());
        $this->artisan('gaiacp:compare-servers')->assertSuccessful();
        $this->assertSame(['other (other.example) is on the server but not billed'], ActivityLog::query()->where('event', 'server.accounts_differ')->latest('id')->first()->properties['items']);

        // Off in Settings → Automation
        $this->whmAccounts['third'] = false;
        config(['gaiacp.automation.compare_servers' => false]);
        $this->artisan('gaiacp:compare-servers')->assertSuccessful();
        $this->assertSame(2, ActivityLog::query()->where('event', 'server.accounts_differ')->count());

        $this->assertContains('gaiacp:compare-servers', SchedulerMonitor::DAILY_COMMANDS);
    }

    public function test_differences_are_fixed_with_one_click_on_either_side(): void
    {
        Mail::fake();
        $this->fakeWhm();
        $this->whmAccounts = ['nikos' => true, 'anna' => false, 'bigger' => false, 'small' => false, 'moved' => false, 'stray' => false];
        $this->whmDomains = ['nikos' => 'nikos.gr', 'anna' => 'anna.gr', 'bigger' => 'bigger.gr', 'small' => 'small.gr', 'moved' => 'new-name.gr', 'stray' => 'stray.gr'];
        $this->whmPlans = ['bigger' => 'pro', 'small' => 'pro'];

        $nikos = $this->hosting(['username' => 'nikos', 'domain' => 'nikos.gr']); // suspended on the server only
        $anna = $this->hosting(['username' => 'anna', 'domain' => 'anna.gr', 'status' => ServiceStatus::Suspended]); // suspended in GaiaCP only
        $bigger = $this->hosting(['username' => 'bigger', 'domain' => 'bigger.gr']);
        $small = $this->hosting(['username' => 'small', 'domain' => 'small.gr']);
        $moved = $this->hosting(['username' => 'moved', 'domain' => 'old-name.gr']);
        $gone = $this->hosting(['username' => 'gone', 'domain' => 'gone.gr']);
        $recreate = $this->hosting(['username' => 'again', 'domain' => 'again.gr', 'password' => 'Secret-123!']);

        $page = Livewire::test(CompareServer::class, ['record' => $this->server->id])
            ->assertSee(__('Unsuspend on the server'))
            ->assertSee(__('Mark terminated in GaiaCP'));

        $fix = fn (Service $service, string $fix) => $page->callAction(TestAction::make('fix')->arguments(['service' => $service->id, 'fix' => $fix]))->assertHasNoActionErrors();

        $fix($nikos, FixServerDifference::UNSUSPEND_ON_SERVER);
        $this->assertFalse($this->whmAccounts['nikos']);
        $this->assertSame(ServiceStatus::Active, $nikos->fresh()->status);

        $fix($anna, FixServerDifference::MARK_ACTIVE);
        $this->assertSame(ServiceStatus::Active, $anna->fresh()->status);
        $this->assertFalse($this->whmAccounts['anna'], 'the server is not touched');

        $fix($bigger, FixServerDifference::TAKE_PACKAGE);
        $this->assertSame('pro', $bigger->fresh()->package);

        $fix($small, FixServerDifference::APPLY_PACKAGE);
        $this->assertContains(['changepackage', ['user' => 'small', 'pkg' => 'basic']], array_map(fn ($call) => [$call[0], array_intersect_key($call[1], ['user' => 1, 'pkg' => 1])], $this->whmCalls));

        $fix($moved, FixServerDifference::TAKE_DOMAIN);
        $this->assertSame('new-name.gr', $moved->fresh()->domain);

        $fix($gone, FixServerDifference::MARK_TERMINATED);
        $this->assertSame(ServiceStatus::Terminated, $gone->fresh()->status);

        $fix($recreate, FixServerDifference::CREATE_ON_SERVER);
        $this->assertArrayHasKey('again', $this->whmAccounts);

        $page->callAction(TestAction::make('suspendAccount')->arguments(['username' => 'stray']))->assertHasNoActionErrors();
        $this->assertTrue($this->whmAccounts['stray']);

        // Only "small" is left: the fake server keeps its old plan after changepackage
        $page->assertSet('comparison.different.0.service.id', $small->id)
            ->assertCount('comparison.different', 1)
            ->assertSet('comparison.missing', []);
        $this->assertSame(0, ActivityLog::query()->where('event', 'provisioning.failed')->count());
        $this->assertSame(4, ActivityLog::query()->where('event', 'service.synced_from_server')->count());
        Mail::assertNothingSent();
    }

    public function test_a_fix_only_acts_on_what_the_last_reading_showed(): void
    {
        $this->fakeWhm();
        $this->whmAccounts = ['maria' => false];
        $this->whmDomains = ['maria' => 'maria.gr'];
        $maria = $this->hosting(['username' => 'maria', 'domain' => 'maria.gr']); // matching: nothing to fix

        Livewire::test(CompareServer::class, ['record' => $this->server->id])
            ->callAction(TestAction::make('fix')->arguments(['service' => $maria->id, 'fix' => FixServerDifference::MARK_TERMINATED]))
            ->assertNotified(__('Read the server again first'));

        $this->assertSame(ServiceStatus::Active, $maria->fresh()->status);
    }
}
