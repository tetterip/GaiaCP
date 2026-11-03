<?php

namespace Tests\Feature;

use App\Enums\MailTemplate;
use App\Enums\UserRole;
use App\Filament\Resources\Clients\Pages\EditClient;
use App\Mail\ClientMail;
use App\Models\Client;
use App\Models\ClientSession;
use App\Models\User;
use App\Notifications\ResetAdminPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/** Client page → Client area → Set password / Send password reset link. */
class ClientPasswordByAdminTest extends TestCase
{
    use RefreshDatabase;

    private function clientUser(array $attributes = []): User
    {
        $client = Client::factory()->create(['email' => 'maria@example.gr']);

        return User::factory()->create(['role' => UserRole::Client, 'client_id' => $client->id, 'email' => $client->email] + $attributes);
    }

    private function page(Client $client)
    {
        return Livewire::test(EditClient::class, ['record' => $client->getRouteKey()]);
    }

    public function test_an_admin_sets_a_clients_password_and_signs_out_their_browsers(): void
    {
        Mail::fake();
        $this->actingAs(User::factory()->create());
        $user = $this->clientUser(['remember_token' => 'old-token']);
        ClientSession::query()->forceCreate(['user_id' => $user->id, 'token_hash' => 'abc', 'last_active_at' => now()]);

        $this->page($user->client)
            ->callAction('setClientAreaPassword', ['password' => 'NewSecret123', 'send_login_email' => false])
            ->assertHasNoActionErrors()
            ->assertNotified(__('Password set'));

        $user->refresh();
        $this->assertTrue(Hash::check('NewSecret123', $user->password));
        $this->assertNotSame('old-token', $user->remember_token);
        $this->assertSame(0, ClientSession::query()->where('user_id', $user->id)->count());
        $this->assertDatabaseHas('activity_logs', ['event' => 'client_area.password_set', 'client_id' => $user->client_id]);
        Mail::assertNothingSent();
    }

    public function test_setting_a_password_can_email_the_login_details_and_creates_a_missing_login(): void
    {
        Mail::fake();
        $this->actingAs(User::factory()->create());
        $client = Client::factory()->create(['email' => 'nikos@example.gr']);

        $this->page($client)
            ->callAction('setClientAreaPassword', ['password' => 'NewSecret123', 'send_login_email' => true])
            ->assertHasNoActionErrors();

        $user = $client->refresh()->user;
        $this->assertSame(UserRole::Client, $user->role);
        $this->assertTrue($user->active);
        $this->assertTrue(Hash::check('NewSecret123', $user->password));
        Mail::assertSent(ClientMail::class, fn (ClientMail $mail) => $mail->template() === MailTemplate::ClientAreaLogin && $mail->hasTo('nikos@example.gr'));
    }

    public function test_a_weak_password_is_refused(): void
    {
        $this->actingAs(User::factory()->create());
        $user = $this->clientUser();
        $old = $user->password;

        $this->page($user->client)
            ->callAction('setClientAreaPassword', ['password' => 'short'])
            ->assertHasActionErrors(['password']);

        $this->assertSame($old, $user->refresh()->password);
    }

    public function test_a_turned_off_login_stays_off_when_its_password_is_set(): void
    {
        Mail::fake();
        $this->actingAs(User::factory()->create());
        $user = $this->clientUser(['active' => false]);

        $this->page($user->client)
            ->callAction('setClientAreaPassword', ['password' => 'NewSecret123', 'send_login_email' => false])
            ->assertHasNoActionErrors();

        $this->assertFalse($user->refresh()->active);
        $this->assertTrue(Hash::check('NewSecret123', $user->password));
    }

    public function test_the_password_of_an_admin_linked_to_the_client_is_never_set_here(): void
    {
        $this->actingAs(User::factory()->create());
        $client = Client::factory()->create(['email' => 'staff@example.gr']);
        User::factory()->create(['email' => 'staff@example.gr', 'client_id' => $client->id]);

        $this->page($client)->assertActionHidden('setClientAreaPassword')->assertActionHidden('sendPasswordResetLink');

        // Nor of an admin who would be linked by inviting.
        $other = Client::factory()->create(['email' => 'boss@example.gr']);
        User::factory()->create(['email' => 'boss@example.gr']);
        $this->page($other)->assertActionHidden('setClientAreaPassword');
    }

    public function test_an_admin_sends_a_password_reset_link_to_the_client(): void
    {
        Notification::fake();
        $this->actingAs(User::factory()->create());
        $user = $this->clientUser();
        $old = $user->password;

        $this->page($user->client)
            ->callAction('sendPasswordResetLink')
            ->assertNotified(__('Password reset link sent to :email', ['email' => 'maria@example.gr']));

        Notification::assertSentTo($user, ResetAdminPassword::class, function (ResetAdminPassword $n) use ($user) {
            $html = (string) $n->toMail($user)->render();

            return $n->byAdmin
                && str_contains($n->url, '/password-reset/reset') && ! str_contains($n->url, '/admin/')
                && str_contains($html, 'We sent you this link');
        });
        $this->assertSame($old, $user->refresh()->password);
        $this->assertDatabaseHas('activity_logs', ['event' => 'client_area.password_reset_sent', 'client_id' => $user->client_id]);
    }

    public function test_no_reset_link_for_a_client_without_a_login_that_is_on(): void
    {
        $this->actingAs(User::factory()->create());

        $this->page(Client::factory()->create(['email' => 'new@example.gr']))->assertActionHidden('sendPasswordResetLink');
        $this->page($this->clientUser(['active' => false])->client)->assertActionHidden('sendPasswordResetLink');
    }
}
