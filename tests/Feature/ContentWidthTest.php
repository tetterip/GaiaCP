<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Pages whose content is a table use the whole width; Home, forms and custom pages keep the default. */
class ContentWidthTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_lists_are_full_width_and_the_dashboard_is_not(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/admin/clients')->assertOk()->assertSee('fi-main fi-width-full', false);
        $this->get('/admin/reports/services')->assertOk()->assertSee('fi-main fi-width-full', false);
        $this->get('/admin')->assertOk()->assertDontSee('fi-main fi-width-full', false)->assertSee('fi-main fi-width-7xl', false);
        $this->get('/admin/clients/create')->assertOk()->assertDontSee('fi-main fi-width-full', false);
    }

    public function test_client_area_lists_are_full_width_and_home_is_not(): void
    {
        $client = Client::factory()->create();
        $this->actingAs(User::factory()->create(['role' => UserRole::Client, 'client_id' => $client->id, 'email' => $client->email]), 'client');

        $this->get('/domains')->assertOk()->assertSee('fi-main fi-width-full', false);
        $this->get('/dashboard')->assertOk()->assertDontSee('fi-main fi-width-full', false);
    }
}
