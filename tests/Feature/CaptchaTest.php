<?php

namespace Tests\Feature;

use App\Captcha\Captcha;
use App\Enums\AdminArea;
use App\Enums\UserRole;
use App\Filament\Client\Pages\Auth\Login as ClientLogin;
use App\Filament\Client\Pages\Auth\Register;
use App\Filament\Client\Pages\Auth\RequestPasswordReset;
use App\Filament\Clusters\Settings\Pages\GeneralSettings;
use App\Filament\Pages\Auth\Login as AdminLogin;
use App\Models\Client;
use App\Models\Setting;
use App\Models\User;
use App\Plugins\PluginManager;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Plugins\Recaptcha\RecaptchaPlugin;
use Plugins\Turnstile\TurnstilePlugin;
use Tests\TestCase;

/** The captcha plugins (Google reCAPTCHA, Cloudflare Turnstile) on the forms Settings → General picks. */
class CaptchaTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config([
            'gaiacp.turnstile.site_key' => 'ts-site', 'gaiacp.turnstile.secret_key' => 'ts-secret',
            'gaiacp.recaptcha.site_key' => 'rc-site', 'gaiacp.recaptcha.secret_key' => 'rc-secret',
            'gaiacp.captcha.provider' => 'turnstile',
            'gaiacp.captcha.forms' => ['client_login', 'client_signup', 'client_password_reset'],
        ]);

        $client = Client::factory()->create(['email' => 'maria@example.gr']);
        $this->user = User::factory()->create(['role' => UserRole::Client, 'client_id' => $client->id, 'email' => 'maria@example.gr', 'password' => 'secret-password']);
    }

    private function answers(bool $success): void
    {
        Http::fake([
            TurnstilePlugin::VERIFY_URL => Http::response(['success' => $success]),
            RecaptchaPlugin::VERIFY_URL => Http::response(['success' => $success]),
        ]);
    }

    private function clientLogin(array $data)
    {
        Filament::setCurrentPanel('client');

        return Livewire::test(ClientLogin::class)->fillForm($data + ['email' => 'maria@example.gr', 'password' => 'secret-password'])->call('authenticate');
    }

    public function test_the_login_shows_the_widget_and_needs_a_solved_check(): void
    {
        $this->get('/login')->assertOk()->assertSee('challenges.cloudflare.com', false)->assertSee('ts-site')->assertSee('size\u0022:\u0022flexible', false)
            ->assertDontSee('api.ready(', false); // Turnstile throws on ready() when its script loads async

        $this->answers(true);
        $this->clientLogin([])->assertHasFormErrors(['captcha' => 'required']);
        $this->assertGuest('client');

        $this->clientLogin(['captcha' => 'token-1'])->assertHasNoFormErrors();
        $this->assertAuthenticatedAs($this->user, 'client');
        Http::assertSent(fn (Request $request) => $request->url() === TurnstilePlugin::VERIFY_URL
            && $request['secret'] === 'ts-secret' && $request['response'] === 'token-1');
    }

    public function test_a_refused_token_or_a_wrong_password_needs_a_new_check(): void
    {
        $this->answers(false);
        $this->clientLogin(['captcha' => 'bad'])->assertHasFormErrors(['captcha'])->assertDispatched('captcha-reset');
        $this->assertGuest('client');

        $this->answers(true);
        $this->clientLogin(['captcha' => 'token-1', 'password' => 'wrong'])->assertHasFormErrors()->assertDispatched('captcha-reset');
        $this->assertNull(session('captcha.verified'));
    }

    public function test_a_solved_token_is_checked_once(): void
    {
        $this->answers(true);

        $this->assertTrue(Captcha::verify('token-1'));
        $this->assertTrue(Captcha::verify('token-1'));
        $this->assertFalse(Captcha::verify(''));
        Http::assertSentCount(1);
    }

    public function test_sign_up_and_forgot_password_too_with_recaptcha(): void
    {
        config(['gaiacp.captcha.provider' => 'recaptcha', 'gaiacp.client_area.signup' => true]);
        $this->answers(true);
        Filament::setCurrentPanel('client');

        $this->get('/signup')->assertOk()->assertSee('www.google.com', false)
            ->assertSeeInOrder(['You log in with your email address and this password.', 'www.google.com', 'accept_terms'], false);
        Livewire::test(Register::class)
            ->fillForm(['first_name' => 'Nikos', 'last_name' => 'P', 'email' => 'nikos@example.gr', 'phone' => '+30 6900000000',
                'address1' => 'Ermou 1', 'city' => 'Athens', 'postcode' => '10563', 'password' => 'Welcome2026x', 'passwordConfirmation' => 'Welcome2026x'])
            ->call('register')
            ->assertHasFormErrors(['captcha' => 'required']);

        Livewire::test(RequestPasswordReset::class)->fillForm(['email' => 'maria@example.gr'])->call('request')->assertHasFormErrors(['captcha' => 'required']);
        Livewire::test(RequestPasswordReset::class)->fillForm(['email' => 'maria@example.gr', 'captcha' => 'token-1'])->call('request')->assertHasNoFormErrors();
        Http::assertSent(fn (Request $request) => $request->url() === RecaptchaPlugin::VERIFY_URL && $request['secret'] === 'rc-secret');
    }

    public function test_only_on_the_ticked_forms_and_only_while_its_plugin_is_on_and_set_up(): void
    {
        $this->assertFalse(Captcha::on('admin_login'));
        $this->get('/admin/login')->assertOk()->assertDontSee('challenges.cloudflare.com', false);

        config(['gaiacp.captcha.forms' => ['admin_login']]);
        $this->get('/admin/login')->assertOk()->assertSee('challenges.cloudflare.com', false);
        Filament::setCurrentPanel('admin');
        Livewire::test(AdminLogin::class)->fillForm(['email' => 'a@example.com', 'password' => 'x'])->call('authenticate')->assertHasFormErrors(['captcha' => 'required']);
        $this->get('/login')->assertOk()->assertDontSee('challenges.cloudflare.com', false);

        config(['gaiacp.turnstile.secret_key' => null]);
        $this->assertNull(Captcha::active());

        config(['gaiacp.turnstile.secret_key' => 'ts-secret']);
        $plugins = app(PluginManager::class);
        $plugins->disable($plugins->find('turnstile'));
        $this->assertNull(Captcha::active());
        $this->assertSame(['recaptcha' => 'Google reCAPTCHA'], Captcha::options());
    }

    public function test_general_settings_pick_the_captcha_and_its_forms(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(GeneralSettings::getUrl())->assertOk()->assertSee('Cloudflare Turnstile')->assertSee('Google reCAPTCHA');

        Livewire::test(GeneralSettings::class)
            ->fillForm(['captcha' => ['provider' => 'recaptcha', 'forms' => ['client_login', 'admin_login']]])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('recaptcha', config('gaiacp.captcha.provider'));
        $this->assertSame(['client_login', 'admin_login'], config('gaiacp.captcha.forms'));
        $this->assertNotNull(Setting::query()->find('captcha.provider'));
    }

    public function test_admins_with_business_settings_see_general_without_the_captcha(): void
    {
        User::factory()->create(); // the first admin is always a super admin
        $this->actingAs(User::factory()->limitedTo([AdminArea::BusinessSettings])->create());

        Livewire::test(GeneralSettings::class)
            ->assertSee('data.invoices.days_before')->assertDontSee('data.captcha.provider')
            ->fillForm(['invoices' => ['days_before' => '7']])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(7, config('gaiacp.invoices.days_before'));
        $this->assertSame('turnstile', config('gaiacp.captcha.provider'));
        $this->get('/admin/settings/billing')->assertRedirect('/admin/settings/general');
    }
}
