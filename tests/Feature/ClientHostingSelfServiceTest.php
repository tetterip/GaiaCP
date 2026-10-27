<?php

namespace Tests\Feature;

use App\Enums\ServiceStatus;
use App\Enums\UserRole;
use App\Filament\Client\Actions\HostingSelfService;
use App\Filament\Client\Resources\Services\Pages\ViewService;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use App\Plugins\PluginManager;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\FakesPlesk;
use Tests\FakesWhm;
use Tests\TestCase;

/** Client area step 5: a client opens cPanel / Plesk signed in and changes the account password on their own hosting. */
class ClientHostingSelfServiceTest extends TestCase
{
    use FakesPlesk, FakesWhm, RefreshDatabase;

    private Client $client;

    private User $user;

    private Service $hosting;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = Client::factory()->create();
        $this->user = User::factory()->create(['role' => UserRole::Client, 'client_id' => $this->client->id, 'email' => $this->client->email]);
        $this->hosting = Service::factory()->for($this->client)->create(['username' => 'maria', 'domain' => 'maria.gr', 'password' => 'Old-pass-123']);
    }

    private function page(?Service $service = null): Testable
    {
        Filament::setCurrentPanel('client');
        $this->actingAs($this->user, 'client');

        return Livewire::test(ViewService::class, ['record' => ($service ?? $this->hosting)->getRouteKey()]);
    }

    private function loginUrl(Service $service): string
    {
        return route('filament.client.services.panel-login', ['service' => $service->id]);
    }

    public function test_the_client_opens_cpanel_signed_in(): void
    {
        $this->fakeWhm();
        $this->whmAccounts['maria'] = false;

        $this->page()
            ->assertActionVisible('panelLogin')
            ->assertActionHasLabel('panelLogin', 'Log in to cPanel')
            ->assertActionHasUrl('panelLogin', $this->loginUrl($this->hosting));

        $this->actingAs($this->user, 'client')
            ->get($this->loginUrl($this->hosting))
            ->assertRedirect('https://whm.test:2083/cpsess123/login/?session=maria:token');

        $this->assertSame(['user' => 'maria', 'service' => 'cpaneld'], $this->whmCalls[0][1]);
        $log = ActivityLog::query()->where('event', 'account.panel_login')->sole();
        $this->assertSame($this->user->id, $log->user_id);
        $this->assertStringNotContainsString('cpsess', $log->description);
    }

    public function test_the_client_opens_plesk_as_the_subscriptions_customer(): void
    {
        $this->fakePlesk();
        $this->hosting->update(['server_id' => Server::factory()->plesk()->create(['hostname' => 'pl1.test'])->id]);
        $this->pleskCustomers[12] = ['login' => 'maria-plesk', 'external_id' => 'gaiacp-client-'.$this->client->id];
        $this->pleskWebspaces['maria.gr'] = ['owner' => 12, 'status' => 0, 'plan' => 'Starter', 'ftp_login' => 'maria', 'ip' => '203.0.113.5'];

        $this->page()->assertActionHasLabel('panelLogin', 'Log in to Plesk');

        $this->actingAs($this->user, 'client')
            ->get($this->loginUrl($this->hosting))
            ->assertRedirect('https://pl1.test:8443/enterprise/rsession_init.php?PLESKSESSID=sess-127.0.0.1-maria-plesk');
    }

    public function test_plesk_gives_no_link_for_a_subscription_the_admin_owns(): void
    {
        $this->fakePlesk();
        $this->hosting->update(['server_id' => Server::factory()->plesk()->create()->id]);
        $this->pleskWebspaces['maria.gr'] = ['owner' => 1, 'status' => 0, 'plan' => 'Starter', 'ftp_login' => 'maria', 'ip' => '203.0.113.5'];

        $this->actingAs($this->user, 'client')
            ->get($this->loginUrl($this->hosting))
            ->assertRedirect(ViewService::getUrl(['record' => $this->hosting], panel: 'client'));

        $this->assertNotContains('server.create_session', $this->pleskOperations());
        $this->assertTrue(ActivityLog::query()->where('event', 'provisioning.failed')->exists());
    }

    public function test_the_login_link_is_only_for_the_clients_own_active_accounts(): void
    {
        $this->fakeWhm();
        $others = Service::factory()->create();
        $suspended = Service::factory()->for($this->client)->create(['status' => ServiceStatus::Suspended]);

        $this->actingAs($this->user, 'client');
        $this->get($this->loginUrl($others))->assertNotFound();
        $this->get($this->loginUrl($suspended))->assertNotFound();
        $this->page($suspended)->assertActionHidden('panelLogin')->assertActionHidden('changePassword');

        $this->assertSame([], $this->whmCalls);
    }

    public function test_the_client_changes_the_password(): void
    {
        $this->fakeWhm();
        $this->whmAccounts['maria'] = false;

        $this->page()
            ->callAction('changePassword', ['password' => 'short'])
            ->assertHasFormErrors(['password']);

        $this->page()
            ->callAction('changePassword', ['password' => 'New-Pass-2026x'])
            ->assertHasNoFormErrors()
            ->assertNotified(__('Password changed'));

        $this->assertSame(['user' => 'maria', 'password' => 'New-Pass-2026x'], array_intersect_key(collect($this->whmCalls)->firstWhere(0, 'passwd')[1], ['user' => 1, 'password' => 1]));
        $this->assertSame('New-Pass-2026x', $this->hosting->refresh()->password);
        $log = ActivityLog::query()->where('event', 'account.password_changed')->sole();
        $this->assertSame($this->user->id, $log->user_id);
        $this->assertStringNotContainsString('New-Pass-2026x', $log->description);
    }

    public function test_a_refused_password_shows_a_plain_message(): void
    {
        $this->fakeWhm();
        $this->whmFailures['passwd'] = 'Sorry, the password you selected cannot be used because it is too weak.';

        $this->page()
            ->callAction('changePassword', ['password' => 'New-Pass-2026x'])
            ->assertNotified(__('We could not change the password.'));

        $this->assertSame('Old-pass-123', $this->hosting->refresh()->password);
    }

    public function test_each_action_has_a_switch_and_needs_the_panel_plugin(): void
    {
        config(['gaiacp.client_area.hosting_login' => false, 'gaiacp.client_area.hosting_password' => false]);

        $this->page()->assertActionHidden('panelLogin')->assertActionHidden('changePassword');
        $this->actingAs($this->user, 'client')->get($this->loginUrl($this->hosting))->assertNotFound();

        config(['gaiacp.client_area.hosting_login' => true, 'gaiacp.client_area.hosting_password' => true]);
        $plugins = app(PluginManager::class);
        $plugins->disable($plugins->find('cpanel'));

        $this->page()->assertActionHidden('panelLogin')->assertActionHidden('changePassword');
    }

    public function test_a_cpanel_shortcut_opens_that_app_signed_in(): void
    {
        $this->fakeWhm();
        $this->whmAccounts['maria'] = false;

        $this->actingAs($this->user, 'client')
            ->get($this->loginUrl($this->hosting).'?to=files')
            ->assertRedirect('https://whm.test:2083/cpsess123/login/?session=maria:token');

        $this->assertSame(['user' => 'maria', 'service' => 'cpaneld', 'app' => 'FileManager_Home'], $this->whmCalls[0][1]);
        $this->assertStringContainsString('(shortcut: files)', ActivityLog::query()->where('event', 'account.panel_login')->sole()->description);
    }

    public function test_a_plesk_shortcut_opens_that_page(): void
    {
        $this->fakePlesk();
        $this->hosting->update(['server_id' => Server::factory()->plesk()->create(['hostname' => 'pl1.test'])->id]);
        $this->pleskCustomers[12] = ['login' => 'maria-plesk', 'external_id' => 'gaiacp-client-'.$this->client->id];
        $this->pleskWebspaces['maria.gr'] = ['owner' => 12, 'status' => 0, 'plan' => 'Starter', 'ftp_login' => 'maria', 'ip' => '203.0.113.5'];

        $this->actingAs($this->user, 'client')
            ->get($this->loginUrl($this->hosting).'?to=files')
            ->assertRedirect('https://pl1.test:8443/enterprise/rsession_init.php?PLESKSESSID=sess-127.0.0.1-maria-plesk&success_redirect_url=%2Fsmb%2Ffile-manager%2Flist');
    }

    public function test_an_unknown_shortcut_is_a_404_without_a_panel_call(): void
    {
        $this->fakeWhm();
        $this->whmAccounts['maria'] = false;

        $this->actingAs($this->user, 'client');
        $this->get($this->loginUrl($this->hosting).'?to=users')->assertNotFound(); // Plesk's, not cPanel's
        $this->get($this->loginUrl($this->hosting).'?to=nope')->assertNotFound();

        $this->assertSame([], $this->whmCalls);
    }

    public function test_no_shortcuts_on_hestia_or_while_login_is_off(): void
    {
        $this->assertArrayHasKey('files', HostingSelfService::shortcuts($this->hosting));

        config(['gaiacp.client_area.hosting_login' => false]);
        $this->assertSame([], HostingSelfService::shortcuts($this->hosting));
        $this->actingAs($this->user, 'client')->get($this->loginUrl($this->hosting).'?to=files')->assertNotFound();

        config(['gaiacp.client_area.hosting_login' => true]);
        $hestia = Service::factory()->for($this->client)->create(['username' => 'maria2', 'server_id' => Server::factory()->hestia()->create()->id]);
        $this->assertSame([], HostingSelfService::shortcuts($hestia));
        $this->get($this->loginUrl($hestia).'?to=files')->assertNotFound();
    }

    public function test_the_page_shows_cpanels_shortcuts(): void
    {
        $this->page()
            ->assertSee(__('Quick shortcuts'))
            ->assertSee(__('File Manager'))
            ->assertSee(e($this->loginUrl($this->hosting).'?to=files'), false);
    }

    public function test_open_website_and_billing_cycle(): void
    {
        $this->hosting->update(['billing_cycle' => 'annually', 'price_cents' => 6000]);

        $this->page()
            ->assertActionVisible('openWebsite')
            ->assertActionHasUrl('openWebsite', 'https://maria.gr')
            ->assertSee(__('Billing cycle'))
            ->assertSee(__('Annually'))
            ->assertDontSee('€60.00 / '.__('Annually'));
    }

    public function test_open_website_is_hidden_without_a_domain(): void
    {
        $this->hosting->update(['domain' => null]);

        $this->page()->assertActionHidden('openWebsite');
    }

    public function test_a_malformed_shortcut_is_a_404(): void
    {
        $this->fakeWhm();

        $this->actingAs($this->user, 'client')->get($this->loginUrl($this->hosting).'?to[]=files')->assertNotFound();
        $this->assertSame([], $this->whmCalls);
    }
}
