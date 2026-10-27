<?php

namespace Tests\Feature;

use App\ClientSessions\ClientSessions;
use App\Enums\UserRole;
use App\Filament\Client\Pages\SignIns;
use App\Filament\Resources\Clients\Pages\EditClient;
use App\Filament\Resources\Clients\RelationManagers\SignInsRelationManager;
use App\Models\Client;
use App\Models\ClientLogin;
use App\Models\ClientSession;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use Tests\TestCase;

/** Client area: signed-in devices, Log out other devices, sign-in history and the admin's Sign-ins tab. */
class ClientSignInsTest extends TestCase
{
    use RefreshDatabase;

    private const CHROME = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0 Safari/537.36';

    private const IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1';

    private function clientUser(): User
    {
        $client = Client::factory()->create(['email' => 'maria@example.gr']);

        return User::factory()->create(['role' => UserRole::Client, 'client_id' => $client->id, 'email' => $client->email, 'password' => 'Secret-2026']);
    }

    private function otherDevice(User $user): ClientSession
    {
        return ClientSession::query()->create(['user_id' => $user->id, 'token_hash' => hash('sha256', 'other'), 'ip_address' => '203.0.113.9', 'user_agent' => self::IPHONE, 'last_active_at' => now()]);
    }

    public function test_the_first_request_after_signing_in_records_the_device_and_the_sign_in_once(): void
    {
        $user = $this->clientUser();

        $this->actingAs($user, 'client')->withHeader('User-Agent', self::CHROME)->get('/dashboard')->assertOk();
        $this->get('/dashboard')->assertOk();

        $session = ClientSession::query()->sole();
        $this->assertSame([$user->id, '127.0.0.1', 'Chrome on Windows'], [$session->user_id, $session->ip_address, $session->device()]);
        $login = ClientLogin::query()->sole();
        $this->assertSame([true, $user->client_id], [$login->succeeded, $login->client_id]);
    }

    public function test_the_client_sees_their_devices_and_logs_out_the_others(): void
    {
        $user = $this->clientUser();
        $this->actingAs($user, 'client')->withHeader('User-Agent', self::CHROME)->get(SignIns::getUrl(panel: 'client'))
            ->assertOk()->assertSee(['Chrome on Windows', __('This device'), __('Recent sign-ins')]);
        $other = $this->otherDevice($user);
        Filament::setCurrentPanel('client');

        Livewire::test(SignIns::class)
            ->assertSee('Safari on iPhone')
            ->callAction('logOutOthers')
            ->assertNotified();

        $this->assertModelMissing($other);
        $this->assertSame(1, ClientSession::query()->count());
        $this->assertDatabaseHas('activity_logs', ['event' => 'client_area.sessions_ended', 'client_id' => $user->client_id]);
    }

    public function test_a_browser_logged_out_elsewhere_has_to_sign_in_again(): void
    {
        $user = $this->clientUser();
        $this->actingAs($user, 'client')->get('/dashboard')->assertOk();

        ClientSession::query()->delete();

        // Filament's email check goes first and sends a signed out browser on to the login page
        $this->get('/dashboard')->assertRedirect();
        $this->get('/email-verification/prompt')->assertRedirect(Filament::getPanel('client')->getLoginUrl());
        $this->assertFalse(Auth::guard('client')->check());
    }

    public function test_signing_out_ends_the_device_and_a_wrong_password_is_in_the_history(): void
    {
        $user = $this->clientUser();
        $this->actingAs($user, 'client')->get('/dashboard')->assertOk();

        Auth::guard('client')->logout();
        $this->assertSame(0, ClientSession::query()->count());

        $this->assertFalse(Auth::guard('client')->attempt(['email' => 'maria@example.gr', 'password' => 'wrong']));
        $this->assertFalse(Auth::guard('client')->attempt(['email' => 'nobody@example.gr', 'password' => 'wrong']));
        $this->assertSame([true, false], ClientLogin::query()->orderBy('id')->pluck('succeeded')->all());
    }

    public function test_an_admin_looking_as_the_client_leaves_no_trace(): void
    {
        $user = $this->clientUser();
        $this->actingAs(User::factory()->create());
        Livewire::test(EditClient::class, ['record' => $user->client->getRouteKey()])->callAction('logInAsClient');

        $this->get('/dashboard')->assertOk();

        $this->assertSame([0, 0], [ClientSession::query()->count(), ClientLogin::query()->count()]);
    }

    public function test_the_admin_sees_the_sign_ins_and_logs_the_client_out_everywhere(): void
    {
        $user = $this->clientUser();
        $this->actingAs($user, 'client')->get('/dashboard');
        $this->otherDevice($user);
        Auth::guard('client')->forgetUser();
        $this->actingAs(User::factory()->create());

        Livewire::test(SignInsRelationManager::class, ['ownerRecord' => $user->client, 'pageClass' => EditClient::class])
            ->assertCanSeeTableRecords(ClientLogin::all())
            ->callAction(TestAction::make('logOutEverywhere')->table())
            ->assertNotified();

        $this->assertSame(0, ClientSession::query()->count());
        $this->assertDatabaseHas('activity_logs', ['event' => 'client_area.sessions_ended', 'client_id' => $user->client_id]);
    }

    public function test_old_rows_are_pruned_and_devices_are_named(): void
    {
        $user = $this->clientUser();
        $this->otherDevice($user)->forceFill(['last_active_at' => now()->subDays(2)])->save();
        ClientLogin::query()->create(['user_id' => $user->id, 'client_id' => $user->client_id, 'succeeded' => true, 'created_at' => now()->subDays(ClientSessions::KEEP_DAYS + 1)]);
        ClientLogin::query()->create(['user_id' => $user->id, 'client_id' => $user->client_id, 'succeeded' => true, 'created_at' => now()]);

        $this->artisan('gaiacp:prune-logs')->assertSuccessful();

        $this->assertSame([0, 1], [ClientSession::query()->count(), ClientLogin::query()->count()]);
        $this->assertSame('Safari on iPhone', ClientSessions::device(self::IPHONE));
        $this->assertSame(__('Unknown device'), ClientSessions::device(null));
    }
}
