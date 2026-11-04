<?php

namespace Tests\Feature;

use App\Enums\ClientAreaLogin;
use App\Enums\UserRole;
use App\Filament\Resources\Clients\Pages\ListClients;
use App\Filament\Resources\Clients\Widgets\ClientSummary;
use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Whether a client can log in to the client area: the client list's column + filter and the client page's Contact card. */
class ClientAreaLoginStatusTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{on: Client, off: Client, none: Client, admin: Client} */
    private function clients(): array
    {
        $login = function (string $email, array $attributes = []): Client {
            $client = Client::factory()->create(['email' => $email]);
            User::factory()->create(['client_id' => $client->id, 'email' => $email, 'role' => UserRole::Client] + $attributes);

            return $client;
        };

        return [
            'on' => $login('on@example.gr'),
            'off' => $login('off@example.gr', ['active' => false]),
            'none' => Client::factory()->create(['email' => 'none@example.gr']),
            'admin' => $login('admin@example.gr', ['role' => UserRole::Admin]),
        ];
    }

    public function test_each_client_gets_its_login_state(): void
    {
        $clients = $this->clients();

        foreach ($clients as $state => $client) {
            $this->assertSame(ClientAreaLogin::from($state), ClientAreaLogin::for($client->fresh()));
        }
    }

    public function test_the_client_list_filters_by_login_state(): void
    {
        $this->actingAs(User::factory()->create());
        $clients = $this->clients();

        foreach ($clients as $state => $client) {
            Livewire::test(ListClients::class)
                ->filterTable('client_area_login', $state)
                ->assertCanSeeTableRecords([$client])
                ->assertCanNotSeeTableRecords(collect($clients)->except($state)->values())
                ->assertTableColumnStateSet('client_area_login', ClientAreaLogin::from($state), $client);
        }
    }

    public function test_the_contact_card_says_when_the_login_is_turned_off(): void
    {
        $this->actingAs(User::factory()->create());
        $clients = $this->clients();

        Livewire::test(ClientSummary::class, ['record' => $clients['off']])
            ->assertSee(__('Client area').': '.__('Login turned off'));
        Livewire::test(ClientSummary::class, ['record' => $clients['none']])
            ->assertSee(__('Client area').': '.__('No login'));
    }
}
