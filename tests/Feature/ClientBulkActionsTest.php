<?php

namespace Tests\Feature;

use App\Enums\ClientMessageKind;
use App\Enums\UserRole;
use App\Filament\Resources\Clients\Pages\ListClients;
use App\Models\Client;
use App\Models\ClientMessage;
use App\Models\Service;
use App\Models\User;
use App\Notifications\ResetAdminPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/** Clients list → pick some → Send message / Send password reset link / Delete. */
class ClientBulkActionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->actingAs(User::factory()->create());
    }

    private function withLogin(array $attributes = []): Client
    {
        $client = Client::factory()->create();
        User::factory()->create(['role' => UserRole::Client, 'client_id' => $client->id, 'email' => $client->email] + $attributes);

        return $client->refresh();
    }

    public function test_send_message_goes_to_the_picked_clients(): void
    {
        $picked = Client::factory()->count(2)->create();
        Client::factory()->create();

        Livewire::test(ListClients::class)
            ->callTableBulkAction('sendMessage', $picked, [
                'kind' => ClientMessageKind::Service->value, 'subject' => 'Hello', 'body' => 'Hello {$client_first_name}',
            ])
            ->assertNotified('Sending to 2 clients');

        $this->assertEqualsCanonicalizing($picked->modelKeys(), ClientMessage::query()->sole()->recipients()->pluck('client_id')->all());
    }

    public function test_password_reset_link_goes_only_to_active_client_logins(): void
    {
        Notification::fake();
        $active = $this->withLogin();
        $off = $this->withLogin(['active' => false]);
        $none = Client::factory()->create();

        Livewire::test(ListClients::class)
            ->callTableBulkAction('sendPasswordResetLink', [$active, $off, $none])
            ->assertNotified('1 link sent');

        Notification::assertSentTo($active->user, ResetAdminPassword::class);
        Notification::assertNotSentTo($off->user, ResetAdminPassword::class);
    }

    public function test_delete_removes_only_clients_without_history_after_typing_delete(): void
    {
        $empty = $this->withLogin();
        $withService = Client::factory()->create();
        Service::factory()->create(['client_id' => $withService->id]);
        $linked = Client::factory()->create();
        $admin = User::factory()->create(['client_id' => $linked->id]);

        Livewire::test(ListClients::class)
            ->callTableBulkAction('delete', [$empty, $withService, $linked], ['confirm' => 'delete'])
            ->assertHasTableBulkActionErrors(['confirm']);
        $this->assertNotNull($empty->fresh());

        Livewire::test(ListClients::class)
            ->callTableBulkAction('delete', [$empty, $withService, $linked], ['confirm' => 'DELETE'])
            ->assertNotified('1 client deleted');

        $this->assertNull($empty->fresh());
        $this->assertSame(0, User::query()->where('client_id', $empty->id)->count());
        $this->assertNotNull($withService->fresh());
        $this->assertNotNull($linked->fresh());
        $this->assertNotNull($admin->fresh());
        $this->assertDatabaseHas('activity_logs', ['event' => 'client.deleted']);
    }
}
