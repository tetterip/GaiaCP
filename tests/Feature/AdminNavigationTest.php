<?php

namespace Tests\Feature;

use App\Filament\Pages\Dashboard;
use App\Filament\Resources\Orders\OrderResource;
use App\Models\Client;
use App\Models\Order;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminNavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_sidebar_is_grouped_in_order(): void
    {
        $this->actingAs(User::factory()->create());
        $this->get(Dashboard::getUrl())->assertOk();

        $groups = collect(Filament::getPanel('admin')->getNavigation())
            ->mapWithKeys(fn ($group) => [$group->getLabel() => collect($group->getItems())->map->getLabel()->values()->all()])
            ->all();

        $this->assertSame([
            'Main' => ['Dashboard', 'Clients', 'Orders', 'Messages to clients', 'Leads'],
            'Billing' => ['Payment notices', 'Quotes', 'Payments', 'Coupons', 'Reports'],
            'Services' => ['Hosting', 'Domains', 'Other services', 'Site checks', 'Upgrade requests', 'Cancellation requests'],
            'Support' => ['Tickets', 'Knowledge base', 'Announcements'],
            'System' => ['Activity log', 'Settings', 'Help'],
        ], $groups);
    }

    public function test_pending_orders_are_counted_in_the_menu(): void
    {
        $client = Client::factory()->create();
        Order::create(['client_id' => $client->id]);
        Order::create(['client_id' => $client->id]);
        Order::create(['client_id' => $client->id, 'status' => 'completed']);

        $this->actingAs(User::factory()->create());
        $orders = collect(Filament::getPanel('admin')->getNavigation())
            ->flatMap(fn ($group) => $group->getItems())
            ->first(fn ($item) => $item->getLabel() === 'Orders');

        $this->assertSame('2', $orders->getBadge());
    }

    public function test_the_top_bar_has_the_search_client_area_and_new_order(): void
    {
        $this->actingAs(User::factory()->create());
        $this->get(Dashboard::getUrl())->assertOk()
            ->assertSee('Search clients, services, domains…')
            ->assertSee('fi-topbar-actions', false)
            ->assertSee('href="'.Filament::getPanel('client')->getUrl().'"', false)
            ->assertSee('href="'.OrderResource::getUrl('create').'"', false)
            ->assertSee('New order');

        // An admin who can't place orders gets only the client area
        $this->actingAs(User::factory()->create(['super_admin' => false, 'admin_areas' => ['billing']]));
        $this->get(Dashboard::getUrl())->assertOk()
            ->assertSee('fi-topbar-client-area', false)
            ->assertDontSee('fi-topbar-new-order', false);
    }

    public function test_both_themes_put_the_admins_card_in_the_sidebar(): void
    {
        $this->actingAs(User::factory()->create(['name' => 'Maria Papadopoulou']));
        $this->get(Dashboard::getUrl())->assertOk()
            ->assertSee('fi-sidebar-footer', false)
            ->assertSee('Maria Papadopoulou')
            // Initials drawn here, no picture from ui-avatars.com
            ->assertSee('data:image/svg+xml;base64,', false)
            ->assertDontSee('ui-avatars.com', false);

        $this->actingAs(User::factory()->create(['theme' => 'flat']));
        $this->get(Dashboard::getUrl())->assertOk()->assertSee('fi-sidebar-footer', false);
    }

    public function test_the_user_menu_items_follow_the_admins_language(): void
    {
        $this->actingAs(User::factory()->create(['locale' => 'el']));
        $this->get(Dashboard::getUrl())->assertOk();

        app()->setLocale('el');
        $labels = collect(Filament::getPanel('admin')->getUserMenuItems())->map->getLabel();

        $this->assertSame('Ρυθμίσεις', $labels['settings']);
        $this->assertSame('Συντομεύσεις πληκτρολογίου', $labels['keyboardShortcuts']);
    }
}
