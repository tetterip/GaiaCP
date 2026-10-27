<?php

namespace Tests\Feature;

use App\Actions\ClientSignup;
use App\Enums\UserRole;
use App\Filament\Client\Pages\Auth\Register;
use App\Models\Client;
use App\Models\Setting;
use App\Models\User;
use App\Plugins\PluginManager;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Plugins\GoogleLogin\GoogleLoginSettings;
use Plugins\GoogleLogin\GoogleOAuth;
use Tests\TestCase;

/** The Google sign-in plugin: "Continue with Google" on the client area's login, matched to clients by email address. */
class GoogleLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config(['gaiacp.google_login.client_id' => 'id-123.apps.googleusercontent.com', 'gaiacp.google_login.client_secret' => 'shh-secret']);
    }

    /** Google's side: the token for the code, then the account. */
    private function fakeGoogle(array $account = []): void
    {
        Http::fake([
            GoogleOAuth::TOKEN_URL => Http::response(['access_token' => 'tok-1', 'id_token' => 'jwt', 'token_type' => 'Bearer']),
            GoogleOAuth::USERINFO_URL => Http::response($account + [
                'sub' => '1098', 'email' => 'maria@example.gr', 'email_verified' => true, 'given_name' => 'Maria', 'family_name' => 'Papa',
            ]),
        ]);
    }

    /** Through Google and back. */
    private function signIn(): TestResponse
    {
        $this->get('/auth/google')->assertRedirectContains(GoogleOAuth::AUTHORIZE_URL);
        $state = session('google_login.state');

        return $this->get('/auth/google/callback?'.http_build_query(['state' => $state, 'code' => 'code-1']));
    }

    private function clientUser(array $attributes = []): User
    {
        $client = Client::factory()->create(['email' => 'maria@example.gr']);

        return User::factory()->create($attributes + ['role' => UserRole::Client, 'client_id' => $client->id, 'email' => 'maria@example.gr']);
    }

    public function test_the_button_shows_once_set_up_and_while_the_plugin_is_on(): void
    {
        $this->get('/login')->assertOk()->assertSee(__('Continue with Google'))->assertSee('/auth/google');

        $plugins = app(PluginManager::class);
        $plugins->disable($plugins->find('google_login'));
        $this->get('/login')->assertDontSee(__('Continue with Google'));
        $this->get('/auth/google')->assertNotFound();

        $plugins->enable($plugins->find('google_login'));
        config(['gaiacp.google_login.client_secret' => null]);
        $this->get('/login')->assertDontSee(__('Continue with Google'));
    }

    public function test_the_help_page_mentions_it_only_while_it_is_set_up(): void
    {
        $this->actingAs($this->clientUser(), 'client');
        $this->get('/help')->assertOk()->assertSee(__('Continue with Google'));

        config(['gaiacp.google_login.client_id' => null]);
        $this->get('/help')->assertOk()->assertDontSee(__('Continue with Google'));
    }

    public function test_the_consent_page_gets_the_client_id_the_redirect_uri_and_a_state(): void
    {
        $location = $this->get('/auth/google')->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        $this->assertSame('id-123.apps.googleusercontent.com', $query['client_id']);
        $this->assertSame(url('/auth/google/callback'), $query['redirect_uri']);
        $this->assertSame('openid email profile', $query['scope']);
        $this->assertSame(session('google_login.state'), $query['state']);
    }

    public function test_a_client_login_with_that_address_is_logged_in(): void
    {
        $this->fakeGoogle(['email' => 'Maria@Example.gr']);
        $user = $this->clientUser(['email_verified_at' => null]);

        $this->signIn()->assertRedirect(Filament::getPanel('client')->getUrl());

        $this->assertAuthenticatedAs($user, 'client');
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        $this->assertDatabaseHas('activity_logs', ['event' => 'client_area.google_login']);
        Http::assertSent(fn (Request $request) => $request->url() === GoogleOAuth::TOKEN_URL && $request['client_secret'] === 'shh-secret'
            && $request['code'] === 'code-1' && $request['redirect_uri'] === url('/auth/google/callback'));
    }

    public function test_a_client_without_a_login_gets_one(): void
    {
        $this->fakeGoogle();
        $client = Client::factory()->create(['email' => 'maria@example.gr']);

        $this->signIn()->assertRedirect(Filament::getPanel('client')->getUrl());

        $user = $client->fresh()->user;
        $this->assertSame(UserRole::Client, $user->role);
        $this->assertTrue($user->hasVerifiedEmail());
        $this->assertAuthenticatedAs($user, 'client');
        $this->assertSame(1, Client::count());
        Mail::assertNothingSent();
    }

    public function test_an_unknown_address_signs_up_when_signing_up_is_on(): void
    {
        $this->fakeGoogle();

        config(['gaiacp.client_area.signup' => false]);
        $this->signIn()->assertRedirect('/login');
        $this->assertGuest('client');
        $this->assertSame(0, User::count());

        config(['gaiacp.client_area.signup' => true]);
        $this->signIn()->assertRedirect('/signup');
        $this->assertSame('maria@example.gr', ClientSignup::pending()['email']);

        // The address Google confirmed is used whatever the form sends, and no password is asked.
        Filament::setCurrentPanel('client');
        Livewire::test(Register::class)
            ->assertSchemaStateSet(['first_name' => 'Maria', 'last_name' => 'Papa', 'email' => 'maria@example.gr'])
            ->assertFormFieldIsHidden('password')
            ->fillForm(['email' => 'someone@else.gr', 'phone' => '+30 6912345678', 'address1' => 'Ermou 1', 'city' => 'Athens', 'postcode' => '10563'])
            ->call('register')
            ->assertHasNoFormErrors();

        $user = User::query()->sole();
        $this->assertSame('maria@example.gr', $user->email);
        $this->assertTrue($user->hasVerifiedEmail());
        $this->assertNull(ClientSignup::pending());
        $this->assertDatabaseHas('activity_logs', ['event' => 'client.signed_up', 'client_id' => $user->client_id]);
    }

    public function test_refused_admins_turned_off_logins_two_factor_and_unconfirmed_addresses(): void
    {
        $this->fakeGoogle(['email' => 'admin@example.gr']);
        User::factory()->create(['email' => 'admin@example.gr']);
        $this->signIn()->assertRedirect('/login');
        $this->assertGuest('client');

        $this->fakeGoogle();
        $user = $this->clientUser(['active' => false]);
        $this->signIn()->assertRedirect('/login');
        $this->assertGuest('client');

        $user->update(['active' => true]);
        $user->saveAppAuthenticationSecret('SECRETSECRETSECRET');
        $this->signIn()->assertRedirect('/login');
        $this->assertGuest('client');

        $user->saveAppAuthenticationSecret(null);
        $this->fakeGoogle(['email_verified' => false]);
        $this->signIn()->assertRedirect('/login');
        $this->assertGuest('client');
    }

    public function test_a_wrong_or_missing_state_is_refused(): void
    {
        $this->fakeGoogle();
        $this->clientUser();

        $this->get('/auth/google');
        $this->get('/auth/google/callback?state=forged&code=code-1')->assertRedirect('/login');
        $this->get('/auth/google/callback?state=&code=code-1')->assertRedirect('/login');

        $this->assertGuest('client');
        Http::assertNothingSent();
    }

    public function test_google_refusing_the_code_shows_a_message(): void
    {
        Http::fake([GoogleOAuth::TOKEN_URL => Http::response(['error' => 'invalid_grant'], 400)]);
        $this->clientUser();

        $this->signIn()->assertRedirect('/login');
        $this->assertGuest('client');
    }

    public function test_settings_store_the_secret_encrypted_and_show_the_redirect_uri(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(GoogleLoginSettings::class)
            ->assertSet('data.google_login.client_secret', null)
            ->assertSchemaStateSet(['redirect_uri' => url('/auth/google/callback')])
            ->fillForm(['google_login' => ['client_id' => 'new-id', 'client_secret' => 'new-secret']])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertNotSame('new-secret', Setting::query()->find('google_login.client_secret')->value);
        $this->assertSame('new-secret', config('gaiacp.google_login.client_secret'));

        // Blank keeps it.
        Livewire::test(GoogleLoginSettings::class)->fillForm(['google_login' => ['client_id' => 'new-id', 'client_secret' => '']])->call('save')->assertHasNoFormErrors();
        $this->assertSame('new-secret', config('gaiacp.google_login.client_secret'));
    }
}
