<?php

namespace Tests\Feature;

use App\Filament\Clusters\Settings\SettingsCluster;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\ActivityLog;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Auth\Pages\Login;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class AdminUsersTest extends TestCase
{
    use RefreshDatabase;

    private User $me;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->me = User::factory()->create(['name' => 'Alex', 'email' => 'me@example.com']));
    }

    public function test_the_list_shows_the_admins_and_a_settings_card(): void
    {
        $other = User::factory()->create(['email' => 'other@example.com']);

        Livewire::test(ListUsers::class)
            ->assertCanSeeTableRecords([$this->me, $other])
            ->assertSee('You');

        $this->get(SettingsCluster::getUrl())->assertOk()->assertSee('Admin users');
    }

    public function test_an_admin_adds_another_who_can_then_log_in(): void
    {
        Livewire::test(CreateUser::class)
            ->fillForm(['name' => 'Maria', 'email' => 'maria@example.com', 'password' => 'secret-password'])
            ->call('create')
            ->assertHasNoFormErrors();

        $maria = User::where('email', 'maria@example.com')->sole();
        $this->assertTrue($maria->active);
        $this->assertSame('admin', $maria->role->value);
        $this->assertTrue(Hash::check('secret-password', $maria->password));
        $this->assertDatabaseHas('activity_logs', ['event' => 'user.created', 'user_id' => $this->me->id]);

        auth()->logout();
        Livewire::test(Login::class)
            ->fillForm(['email' => 'maria@example.com', 'password' => 'secret-password'])
            ->call('authenticate')
            ->assertHasNoFormErrors();
        $this->assertAuthenticatedAs($maria);
        $this->assertNotNull($maria->fresh()->last_login_at);
    }

    public function test_the_email_must_be_unique_and_a_password_is_needed(): void
    {
        Livewire::test(CreateUser::class)
            ->fillForm(['name' => 'Copy', 'email' => 'me@example.com', 'password' => ''])
            ->call('create')
            ->assertHasFormErrors(['email' => 'unique', 'password' => 'required']);
    }

    public function test_a_blank_password_keeps_the_current_one_and_a_new_one_replaces_it(): void
    {
        $other = User::factory()->create();
        $hash = $other->password;

        Livewire::test(EditUser::class, ['record' => $other->getRouteKey()])
            ->assertSchemaStateSet(['password' => null])
            ->fillForm(['name' => 'Renamed'])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame($hash, $other->fresh()->password);
        $this->assertSame('Renamed', $other->fresh()->name);

        Livewire::test(EditUser::class, ['record' => $other->getRouteKey()])
            ->fillForm(['password' => 'a-new-password'])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertTrue(Hash::check('a-new-password', $other->fresh()->password));
        $this->assertDatabaseHas('activity_logs', ['event' => 'user.password_changed']);
    }

    public function test_a_deactivated_admin_cannot_log_in_or_keep_using_the_panel(): void
    {
        $other = User::factory()->create(['email' => 'other@example.com']);

        Livewire::test(EditUser::class, ['record' => $other->getRouteKey()])
            ->fillForm(['active' => false])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertFalse($other->fresh()->active);
        $this->assertDatabaseHas('activity_logs', ['event' => 'user.deactivated']);

        $this->actingAs($other->fresh());
        $this->get(SettingsCluster::getUrl())->assertForbidden();

        auth()->logout();
        Livewire::test(Login::class)
            ->fillForm(['email' => 'other@example.com', 'password' => 'password'])
            ->call('authenticate')
            ->assertHasFormErrors(['email']);
        $this->assertGuest();
    }

    public function test_you_cannot_deactivate_or_delete_yourself(): void
    {
        Livewire::test(EditUser::class, ['record' => $this->me->getRouteKey()])
            ->assertFormFieldIsDisabled('active')
            ->assertActionHidden(DeleteAction::class)
            ->fillForm(['active' => false])
            ->call('save');

        $this->assertTrue($this->me->fresh()->active);
    }

    public function test_only_admins_without_history_can_be_deleted(): void
    {
        $fresh = User::factory()->create();
        $withHistory = User::factory()->create();
        ActivityLog::create(['user_id' => $withHistory->id, 'event' => 'payment.recorded', 'description' => 'x']);

        Livewire::test(EditUser::class, ['record' => $withHistory->getRouteKey()])
            ->assertActionHidden(DeleteAction::class);

        Livewire::test(EditUser::class, ['record' => $fresh->getRouteKey()])
            ->callAction(DeleteAction::class);
        $this->assertModelMissing($fresh);
        $this->assertDatabaseHas('activity_logs', ['event' => 'user.deleted']);
    }

    public function test_an_admin_turns_off_another_admins_two_factor(): void
    {
        $other = User::factory()->create();
        $other->forceFill(['app_authentication_secret' => 'SECRETSECRETSECR', 'app_authentication_recovery_codes' => ['x']])->save();

        Livewire::test(ListUsers::class)
            ->assertTableActionHidden('disableTwoFactor', $this->me)
            ->callTableAction('disableTwoFactor', $other);

        $this->assertFalse($other->fresh()->hasTwoFactor());
        $this->assertDatabaseHas('activity_logs', ['event' => 'user.2fa_disabled', 'user_id' => $this->me->id]);
    }

    public function test_changing_your_own_password_keeps_you_logged_in(): void
    {
        $this->get(SettingsCluster::getUrl())->assertOk(); // the session remembers the old password

        Livewire::test(EditUser::class, ['record' => $this->me->getRouteKey()])
            ->fillForm(['password' => 'my-new-password'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->actingAs($this->me->fresh()); // as the next request would load it
        $this->get(SettingsCluster::getUrl())->assertOk();
    }
}
