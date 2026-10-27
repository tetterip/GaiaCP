<?php

namespace Tests\Feature;

use App\Models\User;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Auth\Pages\EditProfile;
use Filament\Auth\Pages\Login;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class TwoFactorTest extends TestCase
{
    use RefreshDatabase;

    private function userWithTwoFactor(): User
    {
        $user = User::factory()->create(['email' => 'admin@example.com']);
        $user->forceFill([
            'app_authentication_secret' => app(Google2FA::class)->generateSecretKey(16),
            'app_authentication_recovery_codes' => [Hash::make('recovery-code-1')],
        ])->save();

        return $user;
    }

    public function test_two_factor_is_off_by_default_so_the_password_is_enough(): void
    {
        User::factory()->create(['email' => 'admin@example.com']);

        Livewire::test(Login::class)
            ->fillForm(['email' => 'admin@example.com', 'password' => 'password'])
            ->call('authenticate')
            ->assertHasNoFormErrors();

        $this->assertAuthenticated();
    }

    public function test_the_secret_and_recovery_codes_are_encrypted_and_hidden(): void
    {
        $user = $this->userWithTwoFactor();
        $raw = $user->getConnection()->table('users')->where('id', $user->id)->first();

        $this->assertNotSame($user->app_authentication_secret, $raw->app_authentication_secret);
        $this->assertArrayNotHasKey('app_authentication_secret', $user->toArray());
        $this->assertArrayNotHasKey('app_authentication_recovery_codes', $user->toArray());
    }

    public function test_a_user_with_two_factor_needs_the_app_code_after_the_password(): void
    {
        $user = $this->userWithTwoFactor();

        $login = Livewire::test(Login::class)
            ->fillForm(['email' => 'admin@example.com', 'password' => 'password'])
            ->call('authenticate');

        $this->assertGuest();
        $this->assertNotNull($login->get('userUndertakingMultiFactorAuthentication'));

        $login->fillForm(['app' => ['code' => '000000']], 'multiFactorChallengeForm')
            ->call('authenticate')
            ->assertHasFormErrors(['app.code'], 'multiFactorChallengeForm');
        $this->assertGuest();

        $code = app(Google2FA::class)->getCurrentOtp($user->app_authentication_secret);
        $login->fillForm(['app' => ['code' => $code]], 'multiFactorChallengeForm')
            ->call('authenticate')
            ->assertHasNoFormErrors([], 'multiFactorChallengeForm');

        $this->assertAuthenticatedAs($user);
    }

    public function test_a_recovery_code_works_once_instead_of_the_app_code(): void
    {
        $user = $this->userWithTwoFactor();

        Livewire::test(Login::class)
            ->fillForm(['email' => 'admin@example.com', 'password' => 'password'])
            ->call('authenticate')
            ->fillForm(['app' => ['useRecoveryCode' => true, 'recoveryCode' => 'recovery-code-1']], 'multiFactorChallengeForm')
            ->call('authenticate');

        $this->assertAuthenticatedAs($user);
        $this->assertSame([], $user->fresh()->app_authentication_recovery_codes);
    }

    public function test_the_profile_page_offers_to_set_it_up(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/admin/profile')->assertOk();

        Livewire::test(EditProfile::class)
            ->assertSee(__('filament-panels::auth/multi-factor/app/actions/set-up.label'));
    }

    public function test_the_command_turns_it_off_for_a_locked_out_user(): void
    {
        $user = $this->userWithTwoFactor();

        $this->artisan('gaiacp:disable-2fa', ['email' => 'admin@example.com'])->assertSuccessful();

        $this->assertFalse(AppAuthentication::make()->isEnabled($user->fresh()));
        $this->assertDatabaseHas('activity_logs', ['event' => 'user.2fa_disabled']);
        $this->artisan('gaiacp:disable-2fa', ['email' => 'nobody@example.com'])->assertFailed();
    }
}
