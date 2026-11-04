<?php

namespace Tests\Feature;

use App\Filament\Pages\Auth\RequestPasswordReset;
use App\Models\ActivityLog;
use App\Models\User;
use App\Notifications\ResetAdminPassword;
use Filament\Auth\Pages\Login;
use Filament\Auth\Pages\PasswordReset\ResetPassword;
use Filament\Facades\Filament;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Markdown;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
    }

    public function test_the_login_screen_links_to_forgot_password(): void
    {
        $this->get('/admin/login')
            ->assertOk()
            ->assertSee(Filament::getRequestPasswordResetUrl(), escape: false);

        $this->get(Filament::getRequestPasswordResetUrl())->assertOk();
    }

    public function test_an_admin_gets_a_reset_link_by_email_at_once(): void
    {
        Notification::fake();
        $admin = User::factory()->create(['email' => 'admin@example.com']);

        Livewire::test(RequestPasswordReset::class)
            ->fillForm(['email' => 'admin@example.com'])
            ->call('request')
            ->assertNotified(__('Check your email'));

        Notification::assertSentTo($admin, ResetAdminPassword::class, function (ResetAdminPassword $notification) use ($admin) {
            $mail = $notification->toMail($admin);

            return str_contains($notification->url, '/admin/password-reset/reset')
                && $mail->notice()->actionUrl === $notification->url
                && ! $notification instanceof ShouldQueue;
        });
        $this->assertDatabaseHas('activity_logs', ['event' => 'user.password_reset_requested']);
    }

    public function test_the_email_is_in_the_admins_panel_language(): void
    {
        Mail::fake();
        config(['gaiacp.locales' => ['en' => 'English', 'el' => 'Ελληνικά']]);
        $admin = User::factory()->create(['email' => 'admin@example.com', 'locale' => 'el']);

        $this->assertSame('el', $admin->preferredLocale());

        Notification::fake();
        Livewire::test(RequestPasswordReset::class)
            ->fillForm(['email' => 'admin@example.com'])
            ->call('request');

        Notification::assertSentTo($admin, ResetAdminPassword::class, function ($notification, $channels, $notifiable, $locale) {
            return $locale === 'el';
        });
    }

    public function test_unknown_and_deactivated_addresses_get_the_same_answer_and_no_email(): void
    {
        Notification::fake();
        User::factory()->create(['email' => 'old@example.com', 'active' => false]);

        foreach (['nobody@example.com', 'old@example.com'] as $email) {
            Livewire::test(RequestPasswordReset::class)
                ->fillForm(['email' => $email])
                ->call('request')
                ->assertNotified(__('Check your email'));
        }

        Notification::assertNothingSent();
    }

    public function test_the_link_sets_a_new_password_and_two_factor_is_still_asked(): void
    {
        $admin = User::factory()->create(['email' => 'admin@example.com']);
        $admin->forceFill(['app_authentication_secret' => app(Google2FA::class)->generateSecretKey(16)])->save();
        $token = Password::broker()->createToken($admin);

        Livewire::test(ResetPassword::class, ['email' => 'admin@example.com', 'token' => $token])
            ->fillForm(['password' => 'new-password-123', 'passwordConfirmation' => 'new-password-123'])
            ->call('resetPassword')
            ->assertHasNoFormErrors();

        $this->assertTrue(Hash::check('new-password-123', $admin->fresh()->password));
        $this->assertTrue(ActivityLog::where('event', 'user.password_reset')->exists());

        Livewire::test(Login::class)
            ->fillForm(['email' => 'admin@example.com', 'password' => 'new-password-123'])
            ->call('authenticate');

        $this->assertGuest();
    }

    public function test_a_signed_in_browser_gets_the_form_and_is_signed_out_of_that_panel_only(): void
    {
        $admin = User::factory()->create(['email' => 'admin@example.com']);
        $url = Filament::getPanel('admin')->getResetPasswordUrl(Password::broker()->createToken($admin), $admin);

        // Filament sends a signed-in browser to the dashboard; the emailed link shows the form instead
        $this->actingAs($admin)->get($url)->assertOk()->assertSee(__('Reset password'));
        $this->assertFalse(Auth::guard('web')->check());
    }

    public function test_the_long_link_under_the_button_breaks_instead_of_scrolling_sideways(): void
    {
        $admin = User::factory()->create(['email' => 'admin@example.com']);
        $notification = new ResetAdminPassword('token');
        $notification->url = 'https://example.com/admin/password-reset/reset?token='.str_repeat('a1', 32).'&email=admin%40example.com';
        $mail = $notification->toMail($admin);

        $html = $mail->render();
        $this->assertStringContainsString('overflow-wrap: anywhere;', $html);
        $this->assertStringContainsString('<wbr', $html);

        $text = app(Markdown::class)->renderText('mail.password-reset', ['client' => false] + $mail->notice()->data());
        $this->assertStringContainsString($notification->url, (string) $text);
    }
}
