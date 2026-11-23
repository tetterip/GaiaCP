<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Client\Pages\Dashboard as ClientDashboard;
use App\Filament\Resources\Clients\ClientResource;
use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Text in a table row that opens a page (each cell is a link) can be selected and copied (filament.selectable-rows). */
class SelectableTableRowsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_tables_with_row_links_keep_their_text_selectable(): void
    {
        $client = Client::factory()->create(['email' => 'maria@example.com']);

        $this->actingAs(User::factory()->create())->get(ClientResource::getUrl())->assertOk()
            ->assertSee('href="'.ClientResource::getUrl('edit', ['record' => $client]).'"', false) // the row opens the client
            ->assertSee('class="fi-ta-col"', false)
            ->assertSee('a.fi-ta-col { -webkit-user-drag: none; -webkit-user-select: text; user-select: text; }', false)
            ->assertSee('data-gcp-selectable-rows', false)
            ->assertSee("window.addEventListener('mousedown'", false) // wire:navigate's press handler kept off cell links
            ->assertSee('selection.containsNode(link, true)', false); // a click ending a selection does not open the row
    }

    public function test_the_client_area_loads_it_too(): void
    {
        $client = Client::factory()->create();
        $user = User::factory()->create(['role' => UserRole::Client, 'client_id' => $client->id, 'super_admin' => false, 'email_verified_at' => now()]);

        $this->actingAs($user, 'client')->get(ClientDashboard::getUrl(panel: 'client'))->assertOk()
            ->assertSee('data-gcp-selectable-rows', false);
    }
}
