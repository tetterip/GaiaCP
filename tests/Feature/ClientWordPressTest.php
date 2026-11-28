<?php

namespace Tests\Feature;

use App\Actions\ServiceLifecycle;
use App\Enums\ServiceStatus;
use App\Enums\UserRole;
use App\Filament\Client\Actions\HostingSelfService;
use App\Filament\Client\Resources\Services\Pages\ViewService;
use App\Livewire\WordPressSites;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\ModuleLog;
use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use App\Provisioning\WordPressInstall;
use App\Provisioning\WpToolkit;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\FakesPlesk;
use Tests\FakesWhm;
use Tests\FakesWpToolkit;
use Tests\TestCase;

/** The client's hosting page lists the account's WordPress sites (WP Toolkit), logs in to one and installs a new one. */
class ClientWordPressTest extends TestCase
{
    use FakesPlesk, FakesWhm, FakesWpToolkit, RefreshDatabase;

    private Client $client;

    private User $user;

    private Service $hosting;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = Client::factory()->create(['email' => 'maria@example.com']);
        $this->user = User::factory()->create(['role' => UserRole::Client, 'client_id' => $this->client->id, 'email' => $this->client->email]);
        $this->hosting = Service::factory()->for($this->client)->create(['username' => 'maria', 'domain' => 'maria.gr']);

        $this->fakeWhm();
        $this->fakePlesk();
        $this->fakeWpToolkit();
        $this->whmAccounts['maria'] = false;
        $this->wpInstallations = [
            $this->wpInstallation(3, 'maria.gr', title: 'Maria\'s shop'),
            $this->wpInstallation(4, 'blog.maria.gr', title: 'Blog'),
            $this->wpInstallation(5, 'other.gr', title: 'Someone else'),
            $this->wpInstallation(6, 'notmaria.gr', title: 'Lookalike'),
        ];
    }

    private function page(?Service $service = null): Testable
    {
        Filament::setCurrentPanel('client');
        $this->actingAs($this->user, 'client');

        return Livewire::test(ViewService::class, ['record' => ($service ?? $this->hosting)->getRouteKey()]);
    }

    private function sites(): Testable
    {
        Filament::setCurrentPanel('client');
        $this->actingAs($this->user, 'client');

        return Livewire::test(WordPressSites::class, ['service' => $this->hosting]);
    }

    private function install(): TestAction
    {
        return TestAction::make('installWordPress')->schemaComponent('wordpress', 'infolist');
    }

    private function loginUrl(int|string $site): string
    {
        return route('filament.client.services.wordpress-login', ['service' => $this->hosting->id, 'site' => $site]);
    }

    public function test_the_page_lists_only_the_accounts_own_sites(): void
    {
        $this->page()->assertSee('WordPress')->assertActionVisible($this->install());

        $this->sites()
            ->assertSee('Maria&#039;s shop', false)
            ->assertSee('Blog')
            ->assertSee('WordPress 6.8.1')
            ->assertSee($this->loginUrl(3), false)
            ->assertDontSee('Someone else')
            ->assertDontSee('Lookalike');

        // WP Toolkit inside WHM takes a WHM session: its cpsess token in the path and the session cookie
        [$method, $path, $headers] = $this->wpCalls[0];
        $this->assertSame(['GET', 'installations'], [$method, $path]);
        $this->assertSame('whostmgrsession=sess%3aabc', $headers['Cookie']);
        $this->assertContains(['create_user_session', ['user' => 'root', 'service' => 'whostmgrd']], $this->whmCalls);
    }

    public function test_the_client_logs_in_to_their_wordpress(): void
    {
        $this->actingAs($this->user, 'client')
            ->get($this->loginUrl(4))
            ->assertRedirect('https://blog.maria.gr/wp-admin/?wpt_login=one-time-4');

        $log = ActivityLog::query()->where('event', 'account.wordpress_login')->sole();
        $this->assertSame($this->user->id, $log->user_id);
        $this->assertStringNotContainsString('one-time', $log->description);
    }

    public function test_another_accounts_site_is_refused_without_a_login_call(): void
    {
        $this->actingAs($this->user, 'client')
            ->get($this->loginUrl(5))
            ->assertRedirect(ViewService::getUrl(['record' => $this->hosting], panel: 'client'));

        $this->assertNotContains('POST', array_column($this->wpCalls, 0));
        $this->assertFalse(ActivityLog::query()->where('event', 'account.wordpress_login')->exists());
    }

    public function test_only_the_clients_own_active_hosting(): void
    {
        $others = Service::factory()->create(['username' => 'nikos', 'domain' => 'nikos.gr']);
        $suspended = Service::factory()->for($this->client)->create(['status' => ServiceStatus::Suspended, 'username' => 'maria2', 'domain' => 'maria2.gr']);

        $this->actingAs($this->user, 'client');
        $this->get(route('filament.client.services.wordpress-login', ['service' => $others->id, 'site' => 3]))->assertNotFound();
        $this->get(route('filament.client.services.wordpress-login', ['service' => $suspended->id, 'site' => 3]))->assertNotFound();
        $this->page($suspended)->assertDontSee('Install WordPress');

        $this->assertSame([], $this->wpCalls);
    }

    public function test_the_switch_hides_it(): void
    {
        config(['gaiacp.client_area.hosting_wordpress' => false]);

        $this->assertFalse(HostingSelfService::canUseWordPress($this->hosting));
        $this->page()->assertDontSee('Install WordPress');
        $this->actingAs($this->user, 'client')->get($this->loginUrl(3))->assertNotFound();
    }

    public function test_panels_without_wp_toolkit_support_have_no_section(): void
    {
        $this->hosting->update(['server_id' => Server::factory()->create(['type' => 'hestia'])->id]);

        $this->assertFalse(HostingSelfService::canUseWordPress($this->hosting->fresh()));
    }

    public function test_a_server_without_wp_toolkit_shows_a_plain_message(): void
    {
        $this->wpMissing = true;

        $this->sites()->assertSee('can&#039;t be shown right now', false);
    }

    public function test_the_client_installs_wordpress(): void
    {
        app()->setLocale('el');

        $this->page()
            ->mountAction($this->install())
            ->assertSchemaStateSet(['email' => 'maria@example.com'])
            ->setActionData(['path' => 'blog', 'title' => 'Το blog μου', 'username' => 'maria', 'password' => 'Strong-pass-123', 'email' => 'maria@example.com'])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertSame([
            'domain' => 'maria.gr', 'installationPath' => 'blog', 'language' => 'el', 'version' => 'latest', 'title' => 'Το blog μου',
            'protocol' => 'https', 'overwrite' => false, 'admin' => ['login' => 'maria', 'password' => 'Strong-pass-123', 'email' => 'maria@example.com'],
        ], $this->wpInstalls[0]);

        $log = ActivityLog::query()->where('event', 'account.wordpress_installed')->sole();
        $this->assertStringContainsString('maria.gr/blog', $log->description);
        $this->assertStringNotContainsString('Strong-pass-123', $log->description);
    }

    public function test_install_refuses_admin_weak_passwords_and_odd_folders(): void
    {
        $this->page()
            ->callAction($this->install(), ['path' => '../x', 'title' => 'Site', 'username' => 'admin', 'password' => 'short', 'email' => 'maria@example.com'])
            ->assertHasActionErrors(['path', 'username', 'password']);

        $this->assertSame([], $this->wpInstalls);
    }

    public function test_plesk_uses_its_own_wp_toolkit_api_with_the_servers_login(): void
    {
        $this->hosting->update(['server_id' => Server::factory()->plesk()->create(['hostname' => 'pl1.test', 'username' => 'admin', 'api_token' => 'secret'])->id]);

        $this->actingAs($this->user, 'client')
            ->get($this->loginUrl(3))
            ->assertRedirect('https://maria.gr/wp-admin/?wpt_login=one-time-3');

        $this->assertSame('Basic '.base64_encode('admin:secret'), $this->wpCalls[0][2]['Authorization']);
        $this->assertSame(['POST', 'installations/3/login'], array_slice($this->wpCalls[1], 0, 2));
    }

    public function test_the_module_log_hides_the_login_link_and_password(): void
    {
        config(['gaiacp.debug.module_log' => true]);

        $this->actingAs($this->user, 'client')->get($this->loginUrl(3));
        app(ServiceLifecycle::class)->installWordPress($this->hosting, new WordPressInstall('', 'Site', 'maria', 'Strong-pass-123', 'maria@example.com'));

        $logs = ModuleLog::query()->pluck('response')->implode("\n").ModuleLog::query()->pluck('request')->implode("\n");
        $this->assertStringNotContainsString('one-time-3', $logs);
        $this->assertStringNotContainsString('Strong-pass-123', $logs);
        $this->assertStringNotContainsString('sess%3aabc', $logs);
    }

    public function test_wordpress_languages(): void
    {
        $this->assertSame(['en_US', 'el', 'de_DE', 'pt_BR', 'en_US'], array_map(WordPressInstall::languageFor(...), ['en', 'el', 'de', 'pt_BR', 'xx']));
        $this->assertSame([], WpToolkit::sitesOf('not a list', 'maria.gr'));
    }
}
