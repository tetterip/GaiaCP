<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Client\Pages\Auth\Login as ClientLogin;
use App\Filament\Pages\Auth\Login;
use App\Models\Client;
use App\Models\EmailLog;
use App\Models\User;
use App\Notifications\SignInLink;
use App\SignIn\EmailLinks;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/** "Email me a sign-in link" (App\SignIn\EmailLinks): on the client area's login, and on /admin's when ticked. */
class SignInLinkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Filament::setCurrentPanel('client');
    }

    private function clientUser(array $attributes = []): User
    {
        $client = Client::factory()->create(['email' => 'maria@example.gr']);

        return User::factory()->create($attributes + ['role' => UserRole::Client, 'client_id' => $client->id, 'email' => 'maria@example.gr']);
    }

    /** The link the email holds, as a path the tests can open. */
    private function sentLink(User $user): string
    {
        $url = null;
        Notification::assertSentTo($user, SignInLink::class, function (SignInLink $notification) use (&$url) {
            $url = $notification->url;

            return true;
        });

        return str_replace(url('/'), '', $url);
    }

    public function test_a_client_asks_for_a_link_and_logs_in_after_pressing_log_in(): void
    {
        $user = $this->clientUser(['email_verified_at' => null]);

        Livewire::test(ClientLogin::class)
            ->assertSee(__('Email me a sign-in link'))
            ->callAction('emailLink', ['email' => 'Maria@Example.gr'])
            ->assertNotified(__('Check your email'));

        $link = $this->sentLink($user);
        $this->assertStringContainsString('/sign-in-link?', $link);

        // Opening it logs nobody in (a mail scanner may open it): the login page asks to press Log in
        $this->get($link)->assertRedirect('/login');
        $this->assertGuest('client');

        Livewire::test(ClientLogin::class)
            ->assertSet('pendingEmail', 'maria@example.gr')
            ->assertSee(__('Log in as :email?', ['email' => 'maria@example.gr']))
            ->call('authenticate');

        $this->assertAuthenticatedAs($user, 'client');
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        $this->assertDatabaseHas('activity_logs', ['event' => 'sign_in.email_link']);

        // Once only
        auth('client')->logout();
        $this->get($link)->assertRedirect('/login');
        Livewire::test(ClientLogin::class)->call('authenticate')->assertNotified();
        $this->assertGuest('client');
    }

    public function test_unknown_addresses_get_the_same_answer_and_no_email(): void
    {
        Livewire::test(ClientLogin::class)
            ->callAction('emailLink', ['email' => 'nobody@example.gr'])
            ->assertNotified(__('Check your email'));

        $off = $this->clientUser(['active' => false]);
        Livewire::test(ClientLogin::class)->callAction('emailLink', ['email' => 'maria@example.gr']);

        Notification::assertNothingSent();
        Notification::assertNotSentTo($off, SignInLink::class);
    }

    public function test_at_most_three_links_per_address_in_fifteen_minutes(): void
    {
        $user = $this->clientUser();

        foreach (range(1, 5) as $i) {
            app(EmailLinks::class)->send('maria@example.gr', 'client');
        }

        Notification::assertSentToTimes($user, SignInLink::class, 3);
    }

    public function test_a_forged_or_expired_link_is_refused(): void
    {
        $user = $this->clientUser();
        $link = str_replace(url('/'), '', app(EmailLinks::class)->url($user, 'client'));

        $this->get(str_replace('user='.$user->id, 'user=999', $link))->assertRedirect('/login');
        $this->travel(16)->minutes();
        $this->get($link)->assertRedirect('/login');

        Livewire::test(ClientLogin::class)->assertSet('pendingEmail', null);
        $this->assertGuest('client');
    }

    public function test_use_another_account_drops_it(): void
    {
        $user = $this->clientUser();
        $this->get(str_replace(url('/'), '', app(EmailLinks::class)->url($user, 'client')));

        Livewire::test(ClientLogin::class)->call('cancelPendingSignIn')->assertSet('pendingEmail', null);
        Livewire::test(ClientLogin::class)->assertSet('pendingEmail', null);
    }

    public function test_two_factor_is_still_asked(): void
    {
        $user = $this->clientUser();
        $user->saveAppAuthenticationSecret($secret = app(Google2FA::class)->generateSecretKey(16));
        $this->get(str_replace(url('/'), '', app(EmailLinks::class)->url($user, 'client')));

        $login = Livewire::test(ClientLogin::class);
        $this->assertNotNull($login->get('userUndertakingMultiFactorAuthentication'));

        $login->fillForm(['app' => ['code' => '000000']], 'multiFactorChallengeForm')->call('authenticate')
            ->assertHasFormErrors(['app.code'], 'multiFactorChallengeForm');
        $this->assertGuest('client');

        $login->fillForm(['app' => ['code' => app(Google2FA::class)->getCurrentOtp($secret)]], 'multiFactorChallengeForm')->call('authenticate');
        $this->assertAuthenticatedAs($user, 'client');
    }

    public function test_a_code_asked_for_another_login_does_not_open_the_linked_one(): void
    {
        $victim = $this->clientUser();
        $victim->saveAppAuthenticationSecret(app(Google2FA::class)->generateSecretKey(16));
        $attackerClient = Client::factory()->create(['email' => 'eve@example.gr']);
        $attacker = User::factory()->create(['role' => UserRole::Client, 'client_id' => $attackerClient->id, 'email' => 'eve@example.gr']);
        $attacker->saveAppAuthenticationSecret($secret = app(Google2FA::class)->generateSecretKey(16));

        // A password login of the attacker's own account waits for its code…
        $login = Livewire::test(ClientLogin::class)->fillForm(['email' => 'eve@example.gr', 'password' => 'password'])->call('authenticate');
        $this->assertNotNull($login->get('userUndertakingMultiFactorAuthentication'));

        // …then a link for another login is opened, and the attacker's own code is sent on the same page
        $this->get(str_replace(url('/'), '', app(EmailLinks::class)->url($victim, 'client')));
        $login->fillForm(['app' => ['code' => app(Google2FA::class)->getCurrentOtp($secret)]], 'multiFactorChallengeForm')->call('authenticate');

        $this->assertGuest('client');
    }

    public function test_admins_only_when_ticked(): void
    {
        Filament::setCurrentPanel('admin');
        $admin = User::factory()->create(['email' => 'alex@example.gr']);

        Livewire::test(Login::class)->assertDontSee(__('Email me a sign-in link'));
        $this->get(str_replace(url('/'), '', app(EmailLinks::class)->url($admin, 'admin')))->assertRedirect('/admin/login');
        Livewire::test(Login::class)->assertSet('pendingEmail', null);

        config(['gaiacp.sign_in.admin_email_link' => true]);
        Livewire::test(Login::class)->callAction('emailLink', ['email' => 'alex@example.gr']);
        $link = $this->sentLink($admin);
        $this->assertStringContainsString('/admin/sign-in-link?', $link);

        $this->get($link)->assertRedirect('/admin/login');
        Livewire::test(Login::class)->call('authenticate');
        $this->assertAuthenticatedAs($admin, 'web');
        $this->assertGuest('client');
    }

    public function test_a_client_link_does_not_open_admin(): void
    {
        config(['gaiacp.sign_in.admin_email_link' => true]);
        $user = $this->clientUser();
        $link = str_replace(url('/'), '', app(EmailLinks::class)->url($user, 'client'));

        $this->get('/admin'.$link)->assertRedirect('/admin/login');
        Filament::setCurrentPanel('admin');
        Livewire::test(Login::class)->assertSet('pendingEmail', null);
    }

    public function test_the_email_log_keeps_a_sign_in_link_email_without_its_link(): void
    {
        $user = $this->clientUser();
        $url = app(EmailLinks::class)->url($user, 'client');

        // Really sent (notifications are faked here), so the email log listener runs
        Mail::send((new SignInLink($url, true))->toMail($user));

        $entry = EmailLog::query()->where('to', 'like', '%maria@example.gr%')->sole();
        $this->assertNull($entry->body);
        $this->assertSame(0, EmailLog::query()->where('body', 'like', '%sign-in-link%')->count());
    }
}
