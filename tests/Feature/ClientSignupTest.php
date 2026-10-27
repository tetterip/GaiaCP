<?php

namespace Tests\Feature;

use App\Actions\ClientAreaAccess;
use App\Enums\AdminArea;
use App\Enums\MailTemplate;
use App\Enums\UserRole;
use App\Filament\Client\Pages\Auth\EmailVerificationPrompt;
use App\Filament\Client\Pages\Auth\Register;
use App\Mail\ClientMail;
use App\Mail\NewClientMail;
use App\Models\Client;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/** Sign up in the client area: behind its switch, makes the client and its login, which confirms its email address first. */
class ClientSignupTest extends TestCase
{
    use RefreshDatabase;

    private const DETAILS = [
        'first_name' => 'Maria', 'last_name' => 'Papa', 'email' => 'Maria@Example.gr', 'phone' => '+30 6912345678',
        'address1' => 'Ermou 1', 'city' => 'Athens', 'postcode' => '10563', 'country' => 'GR',
        'password' => 'Welcome2026x', 'passwordConfirmation' => 'Welcome2026x',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config(['gaiacp.client_area.signup' => true]);
    }

    private function signUp(array $data = []): Testable
    {
        Filament::setCurrentPanel('client');

        return Livewire::test(Register::class)->fillForm($data + self::DETAILS)->call('register');
    }

    public function test_off_by_default_no_page_and_no_link(): void
    {
        config(['gaiacp.client_area.signup' => false]);

        $this->get('/signup')->assertNotFound();
        $this->get('/login')->assertOk()->assertDontSee('/signup');

        config(['gaiacp.client_area.signup' => true]);
        $this->get('/login')->assertOk()->assertSee('/signup');
        $this->get('/signup')->assertOk()->assertSee(__('Create your client account'));
    }

    public function test_signing_up_makes_the_client_and_a_login_that_confirms_its_email_first(): void
    {
        $admin = User::factory()->create(['email' => 'admin@example.com']);
        User::factory()->create(['email' => 'nomail@example.com', 'system_emails' => false]);
        User::factory()->limitedTo([AdminArea::Billing])->create(['email' => 'billing@example.com']);

        $this->signUp()->assertHasNoFormErrors();

        $client = Client::query()->sole();
        $this->assertSame(['Maria', 'Papa', 'maria@example.gr', 'Ermou 1', '10563'], [$client->first_name, $client->last_name, $client->email, $client->address1, $client->postcode]);

        $user = $client->user;
        $this->assertSame(UserRole::Client, $user->role);
        $this->assertTrue(Hash::check('Welcome2026x', $user->password));
        $this->assertFalse($user->hasVerifiedEmail());
        $this->assertDatabaseHas('activity_logs', ['event' => 'client.signed_up', 'client_id' => $client->id]);

        // The confirmation email, at once and always; the admins who receive system emails and open clients hear of it.
        Mail::assertSent(ClientMail::class, fn (ClientMail $mail) => $mail->template() === MailTemplate::EmailVerification && $mail->hasTo('maria@example.gr'));
        Mail::assertSent(NewClientMail::class, 1);
        Mail::assertSent(NewClientMail::class, fn (NewClientMail $mail) => $mail->hasTo($admin->email) && str_contains($mail->render(), 'Maria Papa'));

        // Nothing opens until the address is confirmed.
        $this->actingAs($user, 'client')->get('/dashboard')->assertRedirect(Filament::getPanel('client')->getEmailVerificationPromptUrl());

        $this->get(Filament::getPanel('client')->getVerifyEmailUrl($user))->assertRedirect();
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        $this->get('/dashboard')->assertOk();
    }

    public function test_the_prompt_resends_the_confirmation_email(): void
    {
        $this->signUp()->assertHasNoFormErrors();
        $user = User::query()->where('email', 'maria@example.gr')->sole();

        Filament::setCurrentPanel('client');
        $this->actingAs($user, 'client');
        Livewire::test(EmailVerificationPrompt::class)->callAction('resendNotification');

        Mail::assertSent(ClientMail::class, fn (ClientMail $mail) => $mail->template() === MailTemplate::EmailVerification, 2);
    }

    public function test_an_address_already_here_cant_sign_up_again(): void
    {
        Client::factory()->create(['email' => 'maria@example.gr']);
        $this->signUp()->assertHasFormErrors(['email']);

        User::factory()->create(['email' => 'admin@example.gr']);
        $this->signUp(['email' => 'ADMIN@example.gr'])->assertHasFormErrors(['email']);

        $this->assertSame(1, Client::count());
    }

    public function test_the_details_an_order_needs_are_required(): void
    {
        $this->signUp(['phone' => '', 'address1' => '', 'city' => '', 'postcode' => '', 'password' => 'short', 'passwordConfirmation' => 'short'])
            ->assertHasFormErrors(['phone' => 'required', 'address1' => 'required', 'city' => 'required', 'postcode' => 'required', 'password']);
    }

    public function test_the_new_client_email_has_its_switch(): void
    {
        User::factory()->create();
        config(['gaiacp.automation.new_client_email' => false]);

        $this->signUp()->assertHasNoFormErrors();

        Mail::assertNotSent(NewClientMail::class);
    }

    public function test_logins_made_by_an_admin_and_older_ones_count_as_confirmed(): void
    {
        $client = Client::factory()->create(['email' => 'nikos@example.gr']);
        $this->assertTrue(app(ClientAreaAccess::class)->createLogin($client, 'Welcome2026x', false)->fresh()->hasVerifiedEmail());

        // Admins never confirm (they aren't asked to); the migration marked the logins made before this.
        $this->assertTrue(User::factory()->unverified()->create()->hasVerifiedEmail());
        $this->assertFalse(User::factory()->unverified()->create(['role' => UserRole::Client, 'client_id' => $client->id])->hasVerifiedEmail());
    }
}
