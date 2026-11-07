<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Client;
use App\Models\Lead;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** App\Support\MissingRecord: a deleted record's page goes to its list with a notice instead of a bare 404. */
class MissingRecordTest extends TestCase
{
    use RefreshDatabase;

    public function test_back_to_a_deleted_leads_page_goes_to_the_leads_list(): void
    {
        $this->actingAs(User::factory()->create());
        $lead = Lead::query()->create(['first_name' => 'Maria', 'email' => 'maria@example.gr']);
        $url = "/admin/leads/{$lead->id}/edit";

        $this->get($url)->assertOk();
        $lead->delete();

        $this->get($url)->assertRedirect('/admin/leads');
        Notification::assertNotified('This item no longer exists. It may have been deleted.');
    }

    public function test_the_client_areas_view_pages_do_the_same(): void
    {
        $client = Client::factory()->create(['email' => 'maria@example.gr']);
        $this->actingAs(User::factory()->create(['role' => UserRole::Client, 'client_id' => $client->id, 'email' => $client->email]), 'client');

        $this->get('/services/999999')->assertRedirect('/services');
    }

    public function test_other_404s_stay_404s(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/admin/no-such-page')->assertNotFound();
        $this->getJson('/admin/leads/999999/edit')->assertNotFound();
    }
}
