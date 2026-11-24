<?php

namespace Tests\Feature;

use App\Actions\ServiceLifecycle;
use App\Enums\ServiceStatus;
use App\Filament\Resources\Services\Pages\EditService;
use App\Filament\Resources\Services\ServiceResource;
use App\Livewire\ServerAccountState;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\Service;
use App\Models\User;
use App\Provisioning\AccountState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\FakesWhm;
use Tests\TestCase;

/** The hosting service page's "On the server" and Account → Log in to cPanel: the account as the panel sees it. */
class ServerAccountStateTest extends TestCase
{
    use FakesWhm, RefreshDatabase;

    private Service $hosting;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeWhm();
        Mail::fake();
        $this->hosting = Service::factory()->for(Client::factory())->create(['username' => 'maria', 'domain' => 'maria.gr']);
        $this->actingAs(User::factory()->create());
    }

    public function test_the_state_is_read_from_the_panel_and_kept_for_a_minute(): void
    {
        $this->whmAccounts['maria'] = true;
        $lifecycle = app(ServiceLifecycle::class);

        $this->assertSame(AccountState::Suspended, $lifecycle->serverState($this->hosting));
        $this->whmAccounts['maria'] = false;
        $this->assertSame(AccountState::Suspended, $lifecycle->serverState($this->hosting));
        $this->assertSame(AccountState::Active, $lifecycle->serverState($this->hosting, fresh: true));

        unset($this->whmAccounts['maria']);
        $this->assertSame(AccountState::Missing, $lifecycle->serverState($this->hosting, fresh: true));
    }

    public function test_the_page_shows_it_only_for_an_active_or_suspended_account(): void
    {
        $this->whmAccounts['maria'] = false;

        Livewire::test(EditService::class, ['record' => $this->hosting->id])->assertSee('On the server');

        $this->hosting->update(['status' => ServiceStatus::Pending]);
        Livewire::test(EditService::class, ['record' => $this->hosting->id])->assertDontSee('On the server');
    }

    public function test_matching_states_say_so(): void
    {
        $this->whmAccounts['maria'] = false;

        Livewire::test(ServerAccountState::class, ['service' => $this->hosting])
            ->assertSeeInOrder(['Active', 'Same as in GaiaCP', 'Check again'])
            ->assertDontSee('Does not match GaiaCP.');
    }

    public function test_suspended_on_the_server_but_active_here_is_explained_and_fixed_on_the_server(): void
    {
        $this->whmAccounts['maria'] = true;

        Livewire::test(ServerAccountState::class, ['service' => $this->hosting])
            ->assertSee('Does not match GaiaCP.')
            ->assertSee('the client\'s website and email do not work')
            ->call('unsuspendOnServer')
            ->assertRedirect(ServiceResource::getUrl('edit', ['record' => $this->hosting]));

        $this->assertFalse($this->whmAccounts['maria']);
        $this->assertSame(ServiceStatus::Active, $this->hosting->refresh()->status);
    }

    public function test_marking_it_suspended_here_leaves_the_server_and_the_client_alone(): void
    {
        $this->whmAccounts['maria'] = true;

        Livewire::test(ServerAccountState::class, ['service' => $this->hosting])->call('followServer');

        $this->hosting->refresh();
        $this->assertSame(ServiceStatus::Suspended, $this->hosting->status);
        $this->assertSame(ServiceLifecycle::SUSPENDED_ON_SERVER, $this->hosting->suspension_reason);
        $this->assertNotContains('unsuspendacct', $this->whmFunctions());
        Mail::assertNothingQueued();
        Mail::assertNothingSent();
        $this->assertTrue(ActivityLog::where('event', 'service.status_matched')->exists());
    }

    public function test_active_on_the_server_but_suspended_here(): void
    {
        $this->whmAccounts['maria'] = false;
        $this->hosting->update(['status' => ServiceStatus::Suspended, 'suspension_reason' => 'overdue']);

        Livewire::test(ServerAccountState::class, ['service' => $this->hosting])
            ->assertSee('the client still has their website and email')
            ->call('suspendOnServer');

        $this->assertTrue($this->whmAccounts['maria']);
        $this->assertSame(ServiceStatus::Suspended, $this->hosting->refresh()->status);
        $this->assertSame('overdue', $this->hosting->suspension_reason);
    }

    public function test_a_missing_account_offers_to_create_it(): void
    {
        Livewire::test(ServerAccountState::class, ['service' => $this->hosting])
            ->assertSee('Not found')
            ->assertSee('The account maria does not exist on')
            ->assertSee('Create on server');
    }

    public function test_a_server_that_does_not_answer_is_unknown_not_a_problem(): void
    {
        $this->whmAccounts['maria'] = false;
        $this->whmFailures['accountsummary'] = 'Access denied';
        $this->whmFailures['listaccts'] = 'Access denied';

        Livewire::test(ServerAccountState::class, ['service' => $this->hosting])
            ->assertSee('Unknown')
            ->assertSee('The server did not answer')
            ->assertDontSee('Does not match GaiaCP.');
    }

    public function test_the_admin_logs_in_to_the_clients_cpanel(): void
    {
        $this->whmAccounts['maria'] = false;

        Livewire::test(EditService::class, ['record' => $this->hosting->id])
            ->assertActionVisible('panelLogin');

        $this->get(route('filament.admin.service-panel-login', ['service' => $this->hosting->id]))
            ->assertRedirect('https://whm.test:2083/cpsess123/login/?session=maria:token');
        $this->assertStringNotContainsString('cpsess', ActivityLog::where('event', 'account.panel_login')->sole()->description);
    }

    public function test_no_panel_login_for_a_suspended_account(): void
    {
        $this->hosting->update(['status' => ServiceStatus::Suspended]);

        Livewire::test(EditService::class, ['record' => $this->hosting->id])
            ->assertActionHidden('panelLogin');
    }
}
