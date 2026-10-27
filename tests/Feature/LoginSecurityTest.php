<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Client\Pages\Auth\Login as ClientLogin;
use App\Filament\Client\Pages\Auth\RequestPasswordReset as ClientPasswordReset;
use App\Filament\Clusters\Settings\Pages\LoginSecuritySettings;
use App\Filament\Pages\Auth\Login as AdminLogin;
use App\Filament\Resources\IpBans\Pages\ListIpBans;
use App\Models\Client;
use App\Models\IpBan;
use App\Models\User;
use App\Support\LoginSecurity;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/** Banning an IP address after failed logins on either panel (LoginSecurity), like WHMCS. */
class LoginSecurityTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $clientUser;

    protected function setUp(): void
    {
        parent::setUp();
        config(['gaiacp.login_security' => ['enabled' => true, 'max_attempts' => 3, 'ban_minutes' => 15, 'allowed_ips' => null]]);

        $this->admin = User::factory()->create(['email' => 'admin@example.com', 'super_admin' => true]);
        $client = Client::factory()->create(['email' => 'maria@example.gr']);
        $this->clientUser = User::factory()->create(['role' => UserRole::Client, 'client_id' => $client->id, 'email' => 'maria@example.gr', 'password' => 'secret-password']);
    }

    private function adminLogin(string $password): Testable
    {
        Filament::setCurrentPanel('admin');

        return Livewire::test(AdminLogin::class)->fillForm(['email' => 'admin@example.com', 'password' => $password])->call('authenticate');
    }

    private function clientLogin(string $password): Testable
    {
        Filament::setCurrentPanel('client');

        return Livewire::test(ClientLogin::class)->fillForm(['email' => 'maria@example.gr', 'password' => $password])->call('authenticate');
    }

    public function test_three_wrong_passwords_ban_the_address_for_fifteen_minutes(): void
    {
        $this->clientLogin('wrong')->assertHasFormErrors(['email']);
        $this->adminLogin('wrong')->assertHasFormErrors(['email']); // both panels count together
        $this->assertSame(0, IpBan::query()->count());

        $this->clientLogin('wrong')->assertHasNoFormErrors()->assertRedirect('/login');

        $ban = IpBan::query()->sole();
        $this->assertSame('127.0.0.1', $ban->ip);
        $this->assertSame(3, $ban->attempts);
        $this->assertEqualsWithDelta(now()->addMinutes(15)->timestamp, $ban->expires_at->timestamp, 5);
        $this->assertDatabaseHas('activity_logs', ['event' => 'ip.banned']);

        // The right password no longer helps, and the pages show the ban
        $this->clientLogin('secret-password')->assertRedirect('/login');
        $this->assertGuest('client');
        $this->get('/login')->assertForbidden()->assertSee('127.0.0.1')->assertSee('Failed login attempts: 3')->assertSee($ban->expires_at->timezone('Europe/Athens')->format('d/m/Y (H:i)'));
        $this->get('/admin/login')->assertForbidden();
        $this->get('/password-reset/request')->assertForbidden();

        Filament::setCurrentPanel('client');
        Livewire::test(ClientPasswordReset::class)->fillForm(['email' => 'maria@example.gr'])->call('request')->assertRedirect();

        // Over after fifteen minutes
        $this->travel(16)->minutes();
        $this->get('/login')->assertOk();
        $this->clientLogin('secret-password')->assertHasNoFormErrors();
        $this->assertAuthenticatedAs($this->clientUser, 'client');
    }

    public function test_wrong_two_factor_codes_count_too(): void
    {
        $this->admin->forceFill(['app_authentication_secret' => app(Google2FA::class)->generateSecretKey(16)])->save();

        $login = $this->adminLogin('password');
        $this->assertNotNull($login->get('userUndertakingMultiFactorAuthentication'));

        foreach (range(1, 2) as $try) {
            $login->fillForm(['app' => ['code' => '000000']], 'multiFactorChallengeForm')->call('authenticate')
                ->assertHasFormErrors(['app.code'], 'multiFactorChallengeForm');
        }
        $this->assertSame(0, IpBan::query()->count());

        $login->fillForm(['app' => ['code' => '000000']], 'multiFactorChallengeForm')->call('authenticate')->assertRedirect('/admin/login');
        $this->assertSame(3, IpBan::query()->sole()->attempts);
        $this->assertGuest();
    }

    public function test_a_captcha_or_an_empty_field_is_not_a_failed_login(): void
    {
        Filament::setCurrentPanel('client');
        foreach (range(1, 4) as $try) {
            Livewire::test(ClientLogin::class)->fillForm(['email' => 'maria@example.gr', 'password' => ''])->call('authenticate')
                ->assertHasFormErrors(['password' => 'required']);
        }

        $this->assertSame(0, IpBan::query()->count());
    }

    public function test_allow_listed_addresses_and_switching_it_off_never_ban(): void
    {
        config(['gaiacp.login_security.allowed_ips' => "203.0.113.10\n127.0.0.0/8"]);
        foreach (range(1, 4) as $try) {
            $this->clientLogin('wrong')->assertHasFormErrors(['email']);
        }
        $this->assertSame(0, IpBan::query()->count());

        config(['gaiacp.login_security.allowed_ips' => null, 'gaiacp.login_security.enabled' => false]);
        $this->travel(2)->minutes(); // past Filament's own 5 logins a minute
        foreach (range(1, 4) as $try) {
            $this->clientLogin('wrong')->assertHasFormErrors(['email']);
        }
        $this->assertSame(0, IpBan::query()->count());

        // A ban made while it was on doesn't apply while it is off
        LoginSecurity::ban('127.0.0.1', null, now()->addHour(), 3);
        $this->get('/login')->assertOk();
    }

    public function test_someone_already_logged_in_is_not_shut_out(): void
    {
        LoginSecurity::ban('127.0.0.1', null, now()->addHour(), 3);

        $this->actingAs($this->clientUser, 'client')->get('/dashboard')->assertOk();
        $this->get('/login')->assertRedirect(); // signed in: on to the dashboard, not the ban page
    }

    public function test_ipv6_addresses_are_matched_in_any_spelling(): void
    {
        LoginSecurity::ban('2A02:587:5A0A:B00:0:0:0:1', null, now()->addHour(), 3);

        $this->assertSame('2a02:587:5a0a:b00::1', IpBan::query()->sole()->ip);
        $this->assertNotNull(LoginSecurity::banFor('2a02:587:5a0a:b00::1'));
        $this->assertNull(LoginSecurity::banFor('2a02:587:5a0a:b00::2'));
    }

    public function test_an_admin_lifts_a_ban_and_bans_an_address_by_hand(): void
    {
        $ban = LoginSecurity::ban('198.51.100.7', null, now()->addHour(), 3);
        LoginSecurity::ban('198.51.100.8', null, now()->subHour(), 3); // ended

        $this->actingAs($this->admin);
        Filament::setCurrentPanel('admin');
        $this->get('/admin/settings/banned-ips')->assertOk()->assertSee('198.51.100.7')->assertDontSee('198.51.100.8');

        Livewire::test(ListIpBans::class)->callTableAction('lift', $ban);
        $this->assertModelMissing($ban);
        $this->assertDatabaseHas('activity_logs', ['event' => 'ip.ban_lifted']);

        Livewire::test(ListIpBans::class)->callAction('ban', ['ip' => '192.0.2.44', 'reason' => 'Spam signups'])->assertHasNoActionErrors();
        $manual = IpBan::query()->where('ip', '192.0.2.44')->sole();
        $this->assertNull($manual->expires_at);
        $this->assertSame($this->admin->id, $manual->user_id);
        $this->assertSame('Spam signups', $manual->reasonText());

        // Not your own address
        Livewire::test(ListIpBans::class)->callAction('ban', ['ip' => '127.0.0.1'])->assertHasActionErrors(['ip']);
    }

    public function test_only_super_admins_open_the_pages(): void
    {
        $limited = User::factory()->create(['super_admin' => false, 'admin_areas' => ['clients']]);

        $this->actingAs($limited)->get('/admin/settings/banned-ips')->assertForbidden();
        $this->actingAs($limited)->get('/admin/settings/login-security')->assertForbidden();
    }

    public function test_the_settings_page_saves_and_checks_the_allow_list(): void
    {
        $this->actingAs($this->admin);
        Filament::setCurrentPanel('admin');

        Livewire::test(LoginSecuritySettings::class)
            ->fillForm(['login_security' => ['enabled' => true, 'max_attempts' => 5, 'ban_minutes' => 60, 'allowed_ips' => 'not-an-ip']])
            ->call('save')
            ->assertHasFormErrors(['login_security.allowed_ips']);

        Livewire::test(LoginSecuritySettings::class)
            ->fillForm(['login_security' => ['enabled' => true, 'max_attempts' => 5, 'ban_minutes' => 60, 'allowed_ips' => '203.0.113.10, 2001:db8::/32']])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(5, config('gaiacp.login_security.max_attempts'));
        $this->assertSame(['203.0.113.10', '2001:db8::/32'], LoginSecurity::allowList());
        $this->assertTrue(LoginSecurity::isAllowListed('2001:db8::abcd'));
    }

    public function test_the_nightly_prune_deletes_bans_that_ended_a_month_ago(): void
    {
        LoginSecurity::ban('198.51.100.1', null, now()->subDays(31), 3);
        LoginSecurity::ban('198.51.100.2', null, now()->subDays(2), 3);
        LoginSecurity::ban('198.51.100.3', 'by hand', null);

        $this->artisan('gaiacp:prune-logs')->assertSuccessful();

        $this->assertEqualsCanonicalizing(['198.51.100.2', '198.51.100.3'], IpBan::query()->pluck('ip')->all());
        $this->assertTrue(Hash::check('secret-password', $this->clientUser->password));
    }

    public function test_the_ban_page_takes_the_client_areas_theme(): void
    {
        LoginSecurity::ban('127.0.0.1', null, now()->addHour(), 3);

        $this->get('/login')->assertSee('--bg: #f4f4f5', false)->assertSee('family=commissioner', false);
    }
}
