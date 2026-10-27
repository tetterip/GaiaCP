<?php

namespace Tests\Feature;

use App\Enums\AdminArea;
use App\Enums\ChangeRequestStatus;
use App\Enums\UserRole;
use App\Filament\Client\Pages\Auth\EditProfile;
use App\Filament\Resources\Clients\Pages\EditClient;
use App\Mail\ClientChangeRequestMail;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/** Client area: the client edits email and phone at once; name, company, tax details and address need an admin's approval. */
class ClientProfileTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private User $user;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $this->admin = User::factory()->create(['email' => 'admin@example.com']);
        $this->client = Client::factory()->create([
            'first_name' => 'Maria', 'last_name' => 'Papa', 'email' => 'maria@example.gr', 'phone' => '+30 2100000000',
            'company' => null, 'vat_number' => '094014201', 'city' => 'Athens', 'country' => 'GR',
        ]);
        $this->user = User::factory()->create([
            'role' => UserRole::Client, 'client_id' => $this->client->id, 'email' => 'maria@example.gr', 'password' => 'secret-password',
        ]);
    }

    private function profile(): Testable
    {
        Filament::setCurrentPanel('client');
        $this->actingAs($this->user, 'client');

        return Livewire::test(EditProfile::class);
    }

    public function test_email_and_phone_save_at_once_and_the_login_follows(): void
    {
        $this->profile()
            ->fillForm(['email' => 'maria@new.gr', 'phone' => '+30 6900000000', 'currentPassword' => 'secret-password'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->client->refresh();
        $this->assertSame('maria@new.gr', $this->client->email);
        $this->assertSame('+30 6900000000', $this->client->phone);
        $this->assertSame('maria@new.gr', $this->user->refresh()->email);
        $this->assertSame(0, $this->client->changeRequests()->count());
        Mail::assertNothingSent();
    }

    public function test_a_new_email_needs_the_current_password(): void
    {
        $this->profile()
            ->fillForm(['email' => 'maria@new.gr'])
            ->call('save')
            ->assertHasFormErrors(['currentPassword' => 'required']);

        $this->assertSame('maria@example.gr', $this->client->refresh()->email);
    }

    public function test_changing_details_asks_the_admins_instead_of_saving(): void
    {
        $limited = User::factory()->limitedTo([AdminArea::Billing])->create(['email' => 'billing@example.com']);
        $quiet = User::factory()->create(['email' => 'quiet@example.com', 'system_emails' => false]);

        $this->profile()
            ->fillForm(['details.last_name' => 'Papadopoulou', 'details.company' => 'Papa SA'])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified(__('Saved'));

        $this->assertSame('Papa', $this->client->refresh()->last_name);
        $request = $this->client->changeRequests()->sole();
        $this->assertSame(ChangeRequestStatus::Pending, $request->status);
        $this->assertSame(['last_name' => ['Papa', 'Papadopoulou'], 'company' => [null, 'Papa SA']], $request->fields);
        $this->assertTrue(ActivityLog::query()->where('event', 'client.change_requested')->exists());

        Mail::assertSent(ClientChangeRequestMail::class, fn (ClientChangeRequestMail $mail) => $mail->hasTo('admin@example.com')
            && str_contains($mail->render(), 'Papadopoulou'));
        Mail::assertNotSent(ClientChangeRequestMail::class, fn ($mail) => $mail->hasTo($limited->email) || $mail->hasTo($quiet->email));

        // The profile shows what is waiting
        $this->profile()
            ->assertSee(__('Waiting for approval'))
            ->assertFormSet(['details.last_name' => 'Papadopoulou']);
    }

    public function test_asking_again_replaces_the_pending_request_and_going_back_withdraws_it(): void
    {
        $this->profile()->fillForm(['details.city' => 'Patra'])->call('save');
        $this->profile()->fillForm(['details.city' => 'Volos'])->call('save');

        $this->assertSame(['city' => ['Athens', 'Volos']], $this->client->changeRequests()->sole()->fields);

        $this->profile()->fillForm(['details.city' => 'Athens'])->call('save');

        $this->assertSame(0, $this->client->changeRequests()->count());
    }

    public function test_the_email_switch_in_automation_settings_stops_the_admin_email(): void
    {
        config(['gaiacp.automation.change_request_email' => false]);

        $this->profile()->fillForm(['details.city' => 'Patra'])->call('save');

        $this->assertSame(1, $this->client->changeRequests()->count());
        Mail::assertNothingSent();
    }

    public function test_an_admin_approves_the_changes_on_the_client_page(): void
    {
        $this->profile()->fillForm(['details.first_name' => 'Marianna', 'details.vat_number' => '999999999'])->call('save')->assertHasNoFormErrors();

        Filament::setCurrentPanel('admin');
        $this->actingAs($this->admin, 'web');
        Livewire::test(EditClient::class, ['record' => $this->client->getRouteKey()])
            ->assertActionVisible('reviewChangeRequest')
            ->callAction('reviewChangeRequest')
            ->assertRedirect();

        $this->client->refresh();
        $this->assertSame('Marianna', $this->client->first_name);
        $this->assertSame('999999999', $this->client->vat_number);
        $this->assertSame('Marianna Papa', $this->user->refresh()->name);

        $request = $this->client->changeRequests()->sole();
        $this->assertSame(ChangeRequestStatus::Approved, $request->status);
        $this->assertSame($this->admin->id, $request->decided_by);
    }

    public function test_an_admin_declines_the_changes(): void
    {
        $this->profile()->fillForm(['details.city' => 'Patra'])->call('save');

        Filament::setCurrentPanel('admin');
        $this->actingAs($this->admin, 'web');
        Livewire::test(EditClient::class, ['record' => $this->client->getRouteKey()])
            ->callAction([['name' => 'reviewChangeRequest'], ['name' => 'declineChangeRequest']]);

        $this->assertSame('Athens', $this->client->refresh()->city);
        $this->assertSame(ChangeRequestStatus::Rejected, $this->client->changeRequests()->sole()->status);

        $this->profile()->assertSee(__('Your changes were declined'));
    }
}
