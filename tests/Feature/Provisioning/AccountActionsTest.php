<?php

namespace Tests\Feature\Provisioning;

use App\Enums\ServiceStatus;
use App\Filament\Resources\Servers\Pages\EditServer;
use App\Filament\Resources\Services\Pages\EditService;
use App\Models\Service;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\FakesWhm;
use Tests\TestCase;

class AccountActionsTest extends TestCase
{
    use FakesWhm, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeWhm();
        $this->actingAs(User::factory()->create());
    }

    public function test_test_connection_reports_version_and_packages(): void
    {
        $service = Service::factory()->create();

        Livewire::test(EditServer::class, ['record' => $service->server_id])
            ->callAction('testConnection')
            ->assertNotified(Notification::make()->title('Connected: WHM 11.130.0.4')->body('2 package(s): basic, pro')->success());
    }

    public function test_test_connection_shows_the_error(): void
    {
        $this->whmFailures['version'] = 'Access denied';
        $service = Service::factory()->create();

        Livewire::test(EditServer::class, ['record' => $service->server_id])
            ->callAction('testConnection')
            ->assertNotified('Connection failed');
    }

    public function test_create_account_from_the_service_page(): void
    {
        $service = Service::factory()->create(['status' => ServiceStatus::Pending, 'username' => null, 'domain' => 'shop.gr']);

        Livewire::test(EditService::class, ['record' => $service->id])
            ->assertActionVisible('createAccount')
            ->assertActionHidden('suspend')
            ->callAction('createAccount')
            ->assertNotified('Account created')
            ->assertSchemaStateSet(['status' => ServiceStatus::Active, 'username' => 'shop']);

        $this->assertArrayHasKey('shop', $this->whmAccounts);
    }

    public function test_create_an_active_service_on_its_new_server(): void
    {
        $service = Service::factory()->create(['username' => 'acme', 'domain' => 'shop.gr']);

        Livewire::test(EditService::class, ['record' => $service->id])
            ->assertActionVisible('createAccount')
            ->mountAction('createAccount')
            ->assertSchemaStateSet(['send_welcome' => false])
            ->callMountedAction()
            ->assertNotified('Account created')
            ->assertSchemaStateSet(['status' => ServiceStatus::Active]);

        $this->assertArrayHasKey('acme', $this->whmAccounts);
    }

    public function test_create_is_not_offered_on_a_terminated_service(): void
    {
        $service = Service::factory()->create(['status' => ServiceStatus::Terminated]);

        Livewire::test(EditService::class, ['record' => $service->id])
            ->assertActionHidden('createAccount');
    }

    public function test_suspend_for_another_reason_then_unsuspend(): void
    {
        $service = Service::factory()->create();
        $this->whmAccounts[$service->username] = false;

        Livewire::test(EditService::class, ['record' => $service->id])
            ->callAction('suspend', data: ['reason_type' => 'other', 'reason' => 'Spam complaint'])
            ->assertHasNoActionErrors()
            ->assertSchemaStateSet(['status' => ServiceStatus::Suspended, 'suspension_reason' => 'Spam complaint'])
            ->callAction('unsuspend')
            ->assertSchemaStateSet(['status' => ServiceStatus::Active]);

        $this->assertFalse($this->whmAccounts[$service->username]);
    }

    public function test_terminate_requires_typing_the_username(): void
    {
        $service = Service::factory()->create(['username' => 'acme']);
        $this->whmAccounts['acme'] = false;

        Livewire::test(EditService::class, ['record' => $service->id])
            ->callAction('terminate', data: ['confirm_username' => 'nope'])
            ->assertHasActionErrors(['confirm_username']);

        $this->assertArrayHasKey('acme', $this->whmAccounts);

        Livewire::test(EditService::class, ['record' => $service->id])
            ->callAction('terminate', data: ['confirm_username' => 'acme'])
            ->assertHasNoActionErrors();

        $this->assertArrayNotHasKey('acme', $this->whmAccounts);
        $this->assertSame(ServiceStatus::Terminated, $service->fresh()->status);
    }

    public function test_panel_errors_are_shown_and_nothing_changes(): void
    {
        $this->whmFailures['changepackage'] = 'Package "gold" does not exist';
        $service = Service::factory()->create();

        Livewire::test(EditService::class, ['record' => $service->id])
            ->callAction('changePackage', data: ['package' => 'gold'])
            ->assertNotified('Failed');

        $this->assertSame('basic', $service->fresh()->package);
    }

    public function test_password_is_shown_to_the_admin_and_kept_when_blanked(): void
    {
        $service = Service::factory()->create(['password' => 'Secret-123']);

        Livewire::test(EditService::class, ['record' => $service->id])
            ->assertSchemaStateSet(['password' => 'Secret-123'])
            ->fillForm(['password' => '', 'label' => 'Renamed'])
            ->call('save')
            ->assertHasNoFormErrors();

        $service->refresh();
        $this->assertSame('Renamed', $service->label);
        $this->assertSame('Secret-123', $service->password);
    }

    public function test_suspend_and_unsuspend_follow_the_panel_when_the_status_was_edited_by_hand(): void
    {
        // Suspended on the panel, but the status field was set back to Active.
        $service = Service::factory()->create();
        $this->whmAccounts[$service->username] = true;

        Livewire::test(EditService::class, ['record' => $service->id])
            ->assertActionVisible('unsuspend')
            ->callAction('unsuspend')
            ->assertNotified('Service unsuspended');

        $this->assertFalse($this->whmAccounts[$service->username]);
        $this->assertSame(ServiceStatus::Active, $service->refresh()->status);

        // Active on the panel, but the status field says Suspended.
        $service->update(['status' => ServiceStatus::Suspended, 'suspension_reason' => 'other']);

        Livewire::test(EditService::class, ['record' => $service->id])
            ->assertActionVisible('suspend')
            ->callAction('suspend', data: ['reason_type' => 'other', 'reason' => 'Spam'])
            ->assertNotified('Service suspended');

        $this->assertTrue($this->whmAccounts[$service->username]);
        $this->assertSame('Spam', $service->refresh()->suspension_reason);
    }

    public function test_change_password_on_the_server(): void
    {
        $service = Service::factory()->create(['username' => 'acme']);
        $this->whmAccounts['acme'] = false;

        Livewire::test(EditService::class, ['record' => $service->id])
            ->callAction('changePassword', data: ['password' => 'NewSecret123'])
            ->assertHasNoActionErrors()
            ->assertNotified('Password changed');

        $this->assertContains(['passwd', ['user' => 'acme', 'password' => 'NewSecret123']], collect($this->whmCalls)->map(fn ($c) => [$c[0], array_intersect_key($c[1], ['user' => 1, 'password' => 1])])->all());
        $this->assertSame('NewSecret123', $service->refresh()->password);
    }

    public function test_a_failed_password_change_keeps_the_stored_one(): void
    {
        $this->whmFailures['passwd'] = 'Password too weak';
        $service = Service::factory()->create(['username' => 'acme', 'password' => 'OldSecret123']);
        $this->whmAccounts['acme'] = false;

        Livewire::test(EditService::class, ['record' => $service->id])
            ->callAction('changePassword', data: ['password' => 'NewSecret123'])
            ->assertNotified('Failed');

        $this->assertSame('OldSecret123', $service->refresh()->password);
    }

    public function test_generate_fills_in_a_strong_password(): void
    {
        $service = Service::factory()->create(['password' => 'OldSecret123']);

        $page = Livewire::test(EditService::class, ['record' => $service->id])
            ->callAction(TestAction::make('generatePassword')->schemaComponent('password'));

        $password = $page->get('data.password');
        $this->assertSame(16, strlen($password));
        $this->assertNotSame('OldSecret123', $password);

        $page->call('save')->assertHasNoFormErrors();
        $this->assertSame($password, $service->refresh()->password);
    }

    public function test_auto_terminate_reason_shows_with_its_toggle(): void
    {
        $service = Service::factory()->create();

        Livewire::test(EditService::class, ['record' => $service->id])
            ->assertSchemaComponentHidden('auto_terminate_reason', 'form')
            ->fillForm(['auto_terminate' => true])
            ->assertSchemaComponentVisible('auto_terminate_reason', 'form')
            ->fillForm(['auto_terminate_reason' => 'Client moved away'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Client moved away', $service->refresh()->auto_terminate_reason);
        $this->assertTrue($service->auto_terminate);
    }
}
