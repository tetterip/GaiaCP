<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Resources\Clients\Pages\EditClient;
use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Buttons that open a menu show a chevron, and menus fit their longest choice, in both panels. */
class MenuButtonsTest extends TestCase
{
    use RefreshDatabase;

    private const CHEVRON = '.fi-dropdown-trigger > .fi-btn:not(.gcp-public-theme-btn)::after';

    private const FIT = 'min-width: max-content';

    public function test_the_admin_panel_marks_menu_buttons_and_widens_menus(): void
    {
        $this->actingAs(User::factory()->create());
        $client = Client::factory()->create();

        $this->get(EditClient::getUrl(['record' => $client]))->assertOk()
            ->assertSee('fi-dropdown-trigger', false)
            ->assertSee(self::CHEVRON, false)
            ->assertSee(self::FIT, false);
    }

    public function test_the_client_area_has_the_same_style(): void
    {
        $client = Client::factory()->create(['email' => 'maria@example.gr']);
        $this->actingAs(User::factory()->create(['role' => UserRole::Client, 'client_id' => $client->id, 'email' => $client->email]), 'client');

        $this->get(filament()->getPanel('client')->getProfileUrl())->assertOk()
            ->assertSee(self::CHEVRON, false)
            ->assertSee(self::FIT, false);
    }
}
