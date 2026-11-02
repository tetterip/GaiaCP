<?php

namespace Tests\Feature;

use App\Filament\Clusters\Settings\Pages\LoginSecuritySettings;
use App\Filament\Resources\Servers\Pages\EditServer;
use App\Models\ActivityLog;
use App\Models\Registrar;
use App\Models\Server;
use App\Models\User;
use App\Notifications\ConnectionDetailsChanged;
use App\Support\LoginSecurity;
use App\Support\Settings;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * Settings → Login security → Admin panel (required two-factor authentication, the addresses that may open /admin),
 * the email to super admins when a server's or registrar's login changes, and the password asked to replace one.
 */
class AdminPanelSecurityTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin = User::factory()->create(['email' => 'owner@example.com', 'super_admin' => true]);
        $this->staff = User::factory()->create(['email' => 'staff@example.com', 'super_admin' => false, 'admin_areas' => ['clients']]);
    }

    private function withTwoFactor(User $user): User
    {
        $user->forceFill([
            'app_authentication_secret' => app(Google2FA::class)->generateSecretKey(16),
            'app_authentication_recovery_codes' => [Hash::make('recovery-code-1')],
        ])->save();

        return $user;
    }

    public function test_two_factor_is_not_required_by_default(): void
    {
        $this->actingAs($this->superAdmin)->get('/admin')->assertOk();
    }

    public function test_required_two_factor_sends_admins_without_it_to_set_it_up(): void
    {
        config(['gaiacp.login_security.require_two_factor' => 'admins']);

        $this->actingAs($this->superAdmin)->get('/admin')->assertRedirect('/admin/multi-factor-authentication/set-up');
        $this->actingAs($this->staff)->get('/admin/clients')->assertRedirect('/admin/multi-factor-authentication/set-up');
        $this->actingAs($this->superAdmin)->get('/admin/multi-factor-authentication/set-up')->assertOk();

        $this->actingAs($this->withTwoFactor($this->superAdmin))->get('/admin')->assertOk();
    }

    public function test_it_can_be_required_of_super_admins_only(): void
    {
        config(['gaiacp.login_security.require_two_factor' => 'super_admins']);

        $this->actingAs($this->staff)->get('/admin/clients')->assertOk();
        $this->actingAs($this->superAdmin)->get('/admin')->assertRedirect('/admin/multi-factor-authentication/set-up');
    }

    public function test_the_demo_never_requires_two_factor_or_limits_addresses(): void
    {
        config([
            'gaiacp.demo.enabled' => true,
            'gaiacp.login_security.require_two_factor' => 'admins',
            'gaiacp.login_security.admin_ips' => '203.0.113.10',
        ]);

        $this->assertFalse(LoginSecurity::requiresTwoFactor($this->superAdmin));
        $this->assertTrue(LoginSecurity::admitsToAdmin('198.51.100.7'));
        $this->actingAs($this->superAdmin)->get('/admin')->assertOk();
    }

    public function test_only_the_listed_addresses_open_the_admin_panel(): void
    {
        config(['gaiacp.login_security.admin_ips' => "203.0.113.10\n2001:db8::/32"]);

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])->get('/admin/login')->assertForbidden();
        $this->withServerVariables(['REMOTE_ADDR' => '2001:db8::5'])->get('/admin/login')->assertOk();
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])->get('/login')->assertOk(); // the client area

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])->actingAs($this->superAdmin)->get('/admin')->assertForbidden();
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])->actingAs($this->superAdmin)->get('/admin')->assertOk();

    }

    public function test_the_settings_page_refuses_a_list_without_the_current_address(): void
    {
        $this->actingAs($this->superAdmin);
        Filament::setCurrentPanel('admin');

        Livewire::test(LoginSecuritySettings::class)
            ->fillForm(['login_security' => ['admin_ips' => '203.0.113.10', 'require_two_factor' => 'admins']])
            ->call('save')
            ->assertHasFormErrors(['login_security.admin_ips']);

        Livewire::test(LoginSecuritySettings::class)
            ->fillForm(['login_security' => ['admin_ips' => "203.0.113.10\n127.0.0.1", 'require_two_factor' => 'super_admins']])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(['203.0.113.10', '127.0.0.1'], LoginSecurity::adminIps());
        $this->assertSame('super_admins', config('gaiacp.login_security.require_two_factor'));
    }

    public function test_the_command_empties_the_list_after_a_lock_out(): void
    {
        Settings::save(['login_security.admin_ips' => '203.0.113.10']);

        $this->artisan('gaiacp:admin-ips')->expectsOutputToContain('203.0.113.10')->assertSuccessful();
        $this->artisan('gaiacp:admin-ips --clear')->assertSuccessful();

        $this->assertSame([], LoginSecurity::adminIps());
        $this->assertTrue(ActivityLog::where('event', 'settings.admin_ips_cleared')->exists());
    }

    public function test_replacing_a_servers_token_asks_for_the_admins_password_and_emails_the_super_admins(): void
    {
        Notification::fake();
        $server = Server::factory()->create(['name' => 'nl1', 'api_token' => 'old-token']);
        Notification::assertNothingSent(); // created without a signed-in admin

        $this->actingAs($this->superAdmin);
        Filament::setCurrentPanel('admin');

        Livewire::test(EditServer::class, ['record' => $server->getRouteKey()])
            ->fillForm(['api_token' => 'new-token', 'confirm_password' => 'wrong'])
            ->call('save')
            ->assertHasFormErrors(['confirm_password']);

        $this->assertSame('old-token', $server->fresh()->api_token);

        Livewire::test(EditServer::class, ['record' => $server->getRouteKey()])
            ->fillForm(['api_token' => 'new-token', 'confirm_password' => 'password'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('new-token', $server->fresh()->api_token);
        $this->assertTrue(ActivityLog::where('event', 'server.login_changed')->exists());

        Notification::assertSentTo($this->superAdmin, ConnectionDetailsChanged::class, function (ConnectionDetailsChanged $notice) {
            $html = $notice->toMail($this->superAdmin)->render();

            return $notice->name === 'nl1' && ! str_contains($html, 'new-token') && str_contains($html, 'owner@example.com');
        });
        Notification::assertNotSentTo($this->staff, ConnectionDetailsChanged::class);

        // Other fields save without the password and without an email.
        Notification::fake();
        Livewire::test(EditServer::class, ['record' => $server->getRouteKey()])
            ->fillForm(['notes' => 'Rack 4'])
            ->call('save')
            ->assertHasNoFormErrors();
        Notification::assertNothingSent();
    }

    public function test_super_admins_who_turned_off_system_emails_are_not_emailed(): void
    {
        Notification::fake();
        $this->superAdmin->update(['system_emails' => false]);
        $other = User::factory()->create(['super_admin' => true]);

        $this->actingAs($this->superAdmin);
        Registrar::factory()->create(['name' => 'easy.gr']);

        Notification::assertNotSentTo($this->superAdmin, ConnectionDetailsChanged::class);
        Notification::assertSentTo($other, ConnectionDetailsChanged::class, fn (ConnectionDetailsChanged $n) => $n->kind === 'registrar' && $n->added);
    }
}
