<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Client\Pages\Dashboard as ClientDashboard;
use App\Filament\Resources\Services\ServiceResource;
use App\Models\Client;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** A date can be typed in its field as well as picked from the calendar (filament.typeable-dates). */
class TypeableDatesTest extends TestCase
{
    use RefreshDatabase;

    public function test_date_fields_take_typing_in_the_regions_date_format(): void
    {
        $service = Service::factory()->create(['server_id' => null]);

        $this->actingAs(User::factory()->create())->get(ServiceResource::getUrl('edit', ['record' => $service]))->assertOk()
            ->assertSee('data-typeable-date="DD/MM/YYYY"', false)
            ->assertSee('inputmode="numeric"', false)
            ->assertSee('data-gcp-typeable-dates', false)
            ->assertSee("window.addEventListener('keydown'", false) // Backspace edits the text instead of clearing the date
            ->assertSee('data.setState(date)', false);
    }

    public function test_the_field_follows_the_regions_format(): void
    {
        config(['gaiacp.region.date_format' => 'Y-m-d']);
        $service = Service::factory()->create(['server_id' => null]);

        $this->actingAs(User::factory()->create())->get(ServiceResource::getUrl('edit', ['record' => $service]))->assertOk()
            ->assertSee('data-typeable-date="YYYY-MM-DD"', false);
    }

    public function test_the_client_area_loads_it_too(): void
    {
        $client = Client::factory()->create();
        $user = User::factory()->create(['role' => UserRole::Client, 'client_id' => $client->id, 'super_admin' => false, 'email_verified_at' => now()]);

        $this->actingAs($user, 'client')->get(ClientDashboard::getUrl(panel: 'client'))->assertOk()
            ->assertSee('data-gcp-typeable-dates', false);
    }
}
