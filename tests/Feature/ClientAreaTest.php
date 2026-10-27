<?php

namespace Tests\Feature;

use App\Actions\ClientAreaAccess;
use App\Enums\MailTemplate;
use App\Enums\UserRole;
use App\Filament\Client\Pages\Auth\RequestPasswordReset;
use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\Clients\Pages\EditClient;
use App\Mail\ClientMail;
use App\Models\Client;
use App\Models\User;
use App\Notifications\ResetAdminPassword;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/** Client area step 3: client logins at the site root, invited from the client page. */
class ClientAreaTest extends TestCase
{
    use RefreshDatabase;

    private function invite(Client $client): void
    {
        Livewire::test(EditClient::class, ['record' => $client->getRouteKey()])
            ->callAction('inviteToClientArea')
            ->assertNotified(__('Invitation sent to :email', ['email' => $client->email]));
    }

    private function clientUser(array $attributes = []): User
    {
        $client = Client::factory()->create(['email' => 'maria@example.gr']);

        return User::factory()->create(['role' => UserRole::Client, 'client_id' => $client->id, 'email' => $client->email] + $attributes);
    }

    public function test_inviting_a_client_creates_their_login_and_emails_a_link_to_choose_a_password(): void
    {
        Mail::fake();
        $this->actingAs(User::factory()->create());
        $client = Client::factory()->create(['first_name' => 'Maria', 'last_name' => 'Papa', 'email' => 'maria@example.gr']);

        $this->invite($client);

        $user = $client->refresh()->user;
        $this->assertSame(UserRole::Client, $user->role);
        $this->assertSame('maria@example.gr', $user->email);
        $this->assertSame('Maria Papa', $user->name);
        $this->assertFalse($user->super_admin);

        Mail::assertSent(ClientMail::class, function (ClientMail $mail) {
            $html = $mail->render();

            return $mail->template() === MailTemplate::ClientAreaInvite
                && $mail->hasTo('maria@example.gr')
                && str_contains($html, '/password-reset/reset')
                && ! str_contains($html, '/admin/password-reset');
        });
        $this->assertDatabaseHas('activity_logs', ['event' => 'client_area.invited', 'client_id' => $client->id]);

        // A second invitation reuses the login.
        $this->invite($client);
        $this->assertSame(1, User::query()->where('role', UserRole::Client)->count());
    }

    public function test_inviting_a_client_whose_email_belongs_to_an_admin_links_that_admin(): void
    {
        Mail::fake();
        $admin = User::factory()->create(['email' => 'me@example.gr']);
        $this->actingAs($admin);
        $client = Client::factory()->create(['email' => 'me@example.gr']);

        Livewire::test(EditClient::class, ['record' => $client->getRouteKey()])
            ->callAction('inviteToClientArea')
            ->assertNotified(__('Linked to the admin user :email', ['email' => 'me@example.gr']));

        $this->assertTrue($client->refresh()->user->is($admin));
        $this->assertSame(UserRole::Admin, $admin->refresh()->role);
        $this->assertDatabaseHas('activity_logs', ['event' => 'client_area.admin_linked', 'client_id' => $client->id]);
        Mail::assertNothingSent();
    }

    public function test_client_users_open_only_the_client_area_and_admins_only_admin(): void
    {
        $clientUser = $this->clientUser();
        $this->get('/')->assertRedirect();
        $this->get('/login')->assertOk();

        $this->actingAs($clientUser, 'client')->get('/dashboard')->assertOk()->assertSee($clientUser->client->first_name);
        $this->actingAs($clientUser, 'web')->get('/admin/clients')->assertForbidden();
    }

    public function test_admins_cannot_open_the_client_area_as_themselves(): void
    {
        $this->actingAs(User::factory()->create(), 'client')->get('/dashboard')->assertForbidden();
    }

    public function test_a_turned_off_login_cannot_open_the_client_area(): void
    {
        $this->actingAs(User::factory()->create());
        $clientUser = $this->clientUser();

        Livewire::test(EditClient::class, ['record' => $clientUser->client->getRouteKey()])
            ->callAction('disableClientArea');

        $this->assertFalse($clientUser->refresh()->active);
        $this->actingAs($clientUser, 'client')->get('/dashboard')->assertForbidden();
    }

    public function test_an_admin_can_log_in_as_the_client_and_stays_logged_in_to_admin(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin);
        $clientUser = $this->clientUser();

        Livewire::test(EditClient::class, ['record' => $clientUser->client->getRouteKey()])
            ->callAction('logInAsClient')
            ->assertRedirect(Filament::getPanel('client')->getUrl());

        $this->assertTrue(Auth::guard('client')->user()->is($clientUser));
        $this->assertTrue(Auth::guard('web')->user()->is($admin));
        $this->assertDatabaseHas('activity_logs', ['event' => 'client_area.logged_in_as', 'user_id' => $admin->id]);
    }

    public function test_the_admin_sees_a_bar_in_the_client_area_and_goes_back_to_admin(): void
    {
        $this->actingAs(User::factory()->create());
        $clientUser = $this->clientUser();

        Livewire::test(EditClient::class, ['record' => $clientUser->client->getRouteKey()])->callAction('logInAsClient');

        $this->get('/dashboard')->assertOk()
            ->assertSee(__('You are viewing the client area as :name.', ['name' => $clientUser->client->displayName()]))
            ->assertSee(route('filament.client.stop-viewing'));

        $this->get(route('filament.client.stop-viewing'))
            ->assertRedirect(ClientResource::getUrl('edit', ['record' => $clientUser->client], panel: 'admin'));

        $this->assertFalse(Auth::guard('client')->check());
        $this->assertTrue(Auth::guard('web')->check());
        $this->assertDatabaseHas('activity_logs', ['event' => 'client_area.left', 'client_id' => $clientUser->client_id]);
    }

    public function test_the_client_never_sees_the_bar(): void
    {
        $clientUser = $this->clientUser();

        $this->actingAs($clientUser, 'client')->get('/dashboard')->assertOk()->assertDontSee(__('Back to admin'));
        $this->get(route('filament.client.stop-viewing'))->assertNotFound();
        $this->assertTrue(Auth::guard('client')->check());
    }

    public function test_an_admin_looks_at_a_client_not_invited_yet_through_a_login_kept_turned_off(): void
    {
        Mail::fake();
        $this->actingAs(User::factory()->create());
        $client = Client::factory()->create(['email' => 'nikos@example.gr']);

        Livewire::test(EditClient::class, ['record' => $client->getRouteKey()])->callAction('logInAsClient');

        $login = $client->fresh()->user;
        $this->assertFalse($login->active);
        $this->get('/dashboard')->assertOk();
        Mail::assertNothingOutgoing();

        // The login opens only for the admin looking; the client gets in once invited
        $this->get(route('filament.client.stop-viewing'));
        $this->assertFalse($login->canAccessPanel(Filament::getPanel('client')));

        app(ClientAreaAccess::class)->invite($client->fresh());
        $this->assertTrue($login->fresh()->active);
    }

    public function test_forgot_password_in_the_client_area_emails_client_users_only(): void
    {
        Notification::fake();
        Filament::setCurrentPanel('client');
        $clientUser = $this->clientUser(['locale' => null]);
        $clientUser->client->update(['locale' => 'el']);
        $admin = User::factory()->create(['email' => 'admin@example.gr']);

        foreach (['maria@example.gr', 'admin@example.gr'] as $email) {
            Livewire::test(RequestPasswordReset::class)->fillForm(['email' => $email])->call('request');
        }

        Notification::assertSentTo($clientUser, ResetAdminPassword::class, fn (ResetAdminPassword $n) => ! str_contains($n->url, '/admin/'));
        Notification::assertNotSentTo($admin, ResetAdminPassword::class);
        $this->assertSame('el', $clientUser->preferredLocale());
    }

    public function test_the_login_follows_the_clients_name_and_email(): void
    {
        $clientUser = $this->clientUser();

        $clientUser->client->update(['first_name' => 'Anna', 'email' => 'anna@example.gr']);

        $this->assertSame('anna@example.gr', $clientUser->refresh()->email);
        $this->assertStringStartsWith('Anna ', $clientUser->name);
    }
}
