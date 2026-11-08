<?php

namespace Tests\Feature;

use App\Enums\ServiceStatus;
use App\Filament\Resources\Clients\Pages\ListClients;
use App\Models\Client;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ClientsListTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_list_counts_only_active_services(): void
    {
        $this->actingAs(User::factory()->create());
        $client = Client::factory()->create();
        Service::factory()->for($client)->count(2)->create(['status' => ServiceStatus::Active]);
        Service::factory()->for($client)->create(['status' => ServiceStatus::Terminated]);
        Service::factory()->for($client)->create(['status' => ServiceStatus::Pending]);

        Livewire::test(ListClients::class)
            ->assertSee(__('Active services'))
            ->assertTableColumnStateSet('active_services_count', 2, $client);
    }
}
