<?php

namespace Tests\Feature;

use App\Enums\MailTemplate;
use App\Enums\UserRole;
use App\Filament\Resources\Clients\Pages\CreateClient;
use App\Filament\Resources\Clients\Pages\EditClient;
use App\Mail\ClientMail;
use App\Models\Client;
use App\Models\User;
use App\Support\MailTemplates;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/** A client area login made on New client with a password, and an admin user who is also a client. */
class ClientLoginTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->admin = User::factory()->create(['email' => 'admin@example.com', 'name' => 'Alex']);
        $this->actingAs($this->admin);
    }

    private function create(array $data): Testable
    {
        return Livewire::test(CreateClient::class)
            ->fillForm($data + ['first_name' => 'Maria', 'last_name' => 'Papa', 'email' => 'maria@example.gr'])
            ->call('create');
    }

    private function sentTemplates(): array
    {
        return Mail::sent(ClientMail::class)->map(fn (ClientMail $mail) => $mail->template())->all();
    }

    public function test_a_password_creates_the_login_and_emails_the_details(): void
    {
        $this->create(['client_area_password' => 'Welcome2026x', 'send_signup_email' => true])->assertHasNoFormErrors();

        $user = Client::query()->sole()->user;
        $this->assertSame(UserRole::Client, $user->role);
        $this->assertSame('maria@example.gr', $user->email);
        $this->assertTrue(Hash::check('Welcome2026x', $user->password));
        $this->assertDatabaseHas('activity_logs', ['event' => 'client_area.login_created']);

        // The login email takes the place of the signup email.
        $this->assertSame([MailTemplate::ClientAreaLogin], $this->sentTemplates());
        Mail::assertSent(ClientMail::class, fn (ClientMail $mail) => $mail->hasTo('maria@example.gr')
            && str_contains($html = $mail->render(), 'Welcome2026x') && str_contains($html, Filament::getPanel('client')->getLoginUrl()));

        $this->post('/admin/logout');
        $this->actingAs($user, 'client')->get('/dashboard')->assertOk();
    }

    public function test_the_login_email_can_be_left_out_and_follows_its_template_switch(): void
    {
        $this->create(['client_area_password' => 'Welcome2026x', 'send_login_email' => false])->assertHasNoFormErrors();
        $this->assertNotNull(Client::query()->sole()->user);
        Mail::assertNothingSent();

        MailTemplates::save(MailTemplate::ClientAreaLogin, null, null, enabled: false);
        Livewire::test(CreateClient::class)->assertSchemaStateSet(['send_login_email' => false]);
    }

    public function test_no_password_no_login(): void
    {
        $this->create(['client_area_password' => '', 'send_signup_email' => true])->assertHasNoFormErrors();

        $this->assertNull(Client::query()->sole()->user);
        $this->assertSame([MailTemplate::ClientSignup], $this->sentTemplates());
    }

    public function test_a_weak_password_or_a_taken_email_is_refused(): void
    {
        $this->create(['client_area_password' => 'short'])->assertHasFormErrors(['client_area_password']);

        User::factory()->create(['role' => UserRole::Client, 'client_id' => Client::factory()->create()->id, 'email' => 'maria@example.gr']);
        $this->create(['client_area_password' => 'Welcome2026x'])->assertHasErrors(['data.email']);

        $this->assertSame(1, Client::query()->count());
    }

    public function test_an_admin_with_the_clients_email_is_linked_and_keeps_their_password(): void
    {
        $this->create(['email' => 'admin@example.com', 'client_area_password' => 'Welcome2026x'])
            ->assertHasNoFormErrors()
            ->assertNotified(__('Linked to the admin user :email', ['email' => 'admin@example.com']));

        $client = Client::query()->sole();
        $this->assertTrue($client->user->is($this->admin));
        $this->assertFalse(Hash::check('Welcome2026x', $this->admin->refresh()->password));
        Mail::assertNothingSent();

        // One login for both panels; the client's name doesn't rename the admin.
        $this->actingAs($this->admin, 'client')->get('/dashboard')->assertOk()->assertSee('Maria');
        $this->actingAs($this->admin, 'web')->get('/admin/clients')->assertOk();
        $client->update(['first_name' => 'Anna']);
        $this->assertSame('Alex', $this->admin->refresh()->name);
    }

    public function test_unlinking_an_admin_keeps_their_admin_login(): void
    {
        $client = Client::factory()->create(['email' => 'admin@example.com']);
        $this->admin->update(['client_id' => $client->id]);

        Livewire::test(EditClient::class, ['record' => $client->getRouteKey()])
            ->assertActionHidden('inviteToClientArea')
            ->callAction('disableClientArea')
            ->assertNotified(__('Admin user unlinked'));

        $this->admin->refresh();
        $this->assertNull($this->admin->client_id);
        $this->assertTrue($this->admin->active);
        $this->assertFalse($this->admin->canAccessPanel(Filament::getPanel('client')));
        $this->assertTrue($this->admin->canAccessPanel(Filament::getPanel('admin')));
    }
}
