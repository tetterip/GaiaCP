<?php

namespace Tests\Feature;

use App\Enums\AdminArea;
use App\Enums\UserRole;
use App\Filament\Clusters\Settings\Pages\MenuSettings;
use App\Models\Client;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\User;
use App\Support\ClientMenu;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Settings → Client area → Menus: the client area's side menus arranged, renamed and added to (App\Support\ClientMenu). */
class ClientMenuTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    private ProductGroup $group;

    protected function setUp(): void
    {
        parent::setUp();
        config(['gaiacp.client_area.orders' => true]);
        $this->group = ProductGroup::create(['slug' => 'vps', 'names' => ['en' => 'VPS'], 'icon' => 'cloud']);
        $this->product = Product::factory()->create(['name' => 'Hosting Basic', 'product_group_id' => $this->group->id]);
    }

    private function actAsClient(): void
    {
        $client = Client::factory()->create();
        $this->actingAs(User::factory()->create(['role' => UserRole::Client, 'client_id' => $client->id, 'email' => $client->email]), 'client');
        Filament::setCurrentPanel('client');
    }

    private function save(string $menu, array $items): void
    {
        config(['gaiacp.'.ClientMenu::settingKey($menu) => ['items' => $items, 'seen' => array_keys(ClientMenu::pages($menu))]]);
    }

    public function test_the_menu_as_it_comes_lists_the_pages_under_their_headings(): void
    {
        $rows = ClientMenu::defaultRows(ClientMenu::CLIENT);

        $this->assertSame(['type' => 'page', 'key' => 'Dashboard'], $rows[0]);
        $this->assertSame(['type' => 'page', 'key' => 'Order'], $rows[1]);
        $headings = collect($rows)->where('type', 'heading')->pluck('key')->all();
        $this->assertSame(['services', 'billing', 'support'], $headings);
        $services = array_search(['type' => 'heading', 'key' => 'services'], $rows, true);
        $this->assertSame(['type' => 'page', 'key' => 'ServiceResource.hosting'], $rows[$services + 1]);

        $public = ClientMenu::defaultRows(ClientMenu::PUBLIC);
        $this->assertSame(['type' => 'page', 'key' => 'home'], $public[0]);
        $this->assertContains(['type' => 'heading', 'key' => 'account'], $public);
        $this->assertSame(ClientMenu::defaultRows(ClientMenu::CLIENT), ClientMenu::rows(ClientMenu::CLIENT)); // nothing saved
    }

    public function test_a_saved_menu_is_what_clients_see(): void
    {
        $this->save(ClientMenu::CLIENT, [
            ['type' => 'page', 'key' => 'Dashboard', 'label' => ['en' => 'Start']],
            ['type' => 'link', 'url' => 'https://status.example.com', 'label' => ['en' => 'Our status page'], 'new_tab' => true, 'icon' => 'info'],
            ['type' => 'heading', 'label' => ['en' => 'Shop']],
            ['type' => 'product', 'product' => $this->product->id],
            ['type' => 'category', 'category' => $this->group->id, 'label' => ['en' => 'Virtual servers']],
            ['type' => 'heading', 'key' => 'billing'],
            ['type' => 'page', 'key' => 'PaymentNoticeResource'],
            ['type' => 'page', 'key' => 'ServiceResource.hosting'], // moved under Billing
            ['type' => 'heading', 'key' => 'support', 'label' => ['en' => 'Help desk']],
            ['type' => 'page', 'key' => 'Help'],
            // Domains, Payments, Tickets… taken out
        ]);
        $this->actAsClient();

        $this->get('/services')->assertOk()
            ->assertSeeInOrder(['Start', 'Our status page', 'Shop', 'Hosting Basic', 'Virtual servers', 'Billing', 'Payment notices', 'Hosting', 'Help desk', 'Help'])
            ->assertSee('href="https://status.example.com"', false)
            ->assertSee('target="_blank"', false)
            ->assertSee('/order/vps?product='.$this->product->id, false)
            ->assertDontSee('Other services')
            ->assertDontSee('>Payments<', false);
    }

    public function test_a_page_added_by_an_update_shows_at_the_end_of_its_section_and_one_taken_out_stays_out(): void
    {
        config(['gaiacp.client_area.menu' => ['items' => [
            ['type' => 'page', 'key' => 'Dashboard'],
            ['type' => 'heading', 'key' => 'support'],
            ['type' => 'page', 'key' => 'Help'],
        ], 'seen' => ['Dashboard', 'Help', 'TicketResource']]]);

        $rows = ClientMenu::rows(ClientMenu::CLIENT);

        $this->assertNotContains(['type' => 'page', 'key' => 'TicketResource'], $rows); // taken out when it was saved
        $support = array_search(['type' => 'heading', 'key' => 'support'], $rows, true);
        $this->assertContains(['type' => 'page', 'key' => 'Announcements'], array_slice($rows, $support)); // new: in Support
        $this->assertContains(['type' => 'page', 'key' => 'Order'], array_slice($rows, 0, $support)); // new, no heading: at the top
        $this->assertNotContains(['type' => 'page', 'key' => 'PaymentNoticeResource'], array_slice($rows, 0, $support)); // its heading is gone: at the end
        $this->assertContains(['type' => 'page', 'key' => 'PaymentNoticeResource'], $rows);
    }

    public function test_a_product_that_cant_be_ordered_and_an_empty_heading_are_left_out(): void
    {
        $this->product->update(['hidden' => true]);
        $this->save(ClientMenu::CLIENT, [
            ['type' => 'page', 'key' => 'Dashboard'],
            ['type' => 'heading', 'label' => ['en' => 'Shop']],
            ['type' => 'product', 'product' => $this->product->id],
        ]);
        $this->actAsClient();

        $this->get('/services')->assertOk()->assertDontSee('Shop')->assertDontSee('Hosting Basic');
    }

    public function test_visitors_get_their_own_saved_menu(): void
    {
        config(['gaiacp.public_area.home' => true, 'gaiacp.client_area.public_orders' => true]);
        $this->save(ClientMenu::PUBLIC, [
            ['type' => 'page', 'key' => 'home'],
            ['type' => 'link', 'url' => '/about', 'label' => ['en' => 'About us', 'el' => 'Ποιοι είμαστε']],
            ['type' => 'heading', 'key' => 'account'],
            ['type' => 'page', 'key' => 'login'],
        ]);

        $this->get('/')->assertOk()
            ->assertSeeInOrder(['About us', 'Your account', 'Log in'])
            ->assertSee('href="/about"', false);

        $this->withSession(['public_area.locale' => 'el'])->get('/')->assertOk()->assertSee('Ποιοι είμαστε');
    }

    public function test_the_settings_page_shows_both_menus_and_saves_them(): void
    {
        $this->actingAs(User::factory()->create());

        $page = Livewire::test(MenuSettings::class)->assertOk()->assertSee('Payment notices');

        // Saved as it comes: nothing stored, so the menu keeps following the pages
        $page->call('save')->assertHasNoFormErrors();
        $this->assertNull(config('gaiacp.client_area.menu'));
        $this->assertNull(config('gaiacp.public_area.menu'));

        $items = $page->get('data.client_area.menu.items');
        $first = array_key_first($items);
        $items = [...array_slice($items, 1, null, true), $first => $items[$first]]; // Home dragged to the end
        $items['new'] = ['type' => 'link', 'data' => ['url' => 'https://example.com', 'label' => ['en' => 'Blog'], 'new_tab' => true]];

        $page->set('data.client_area.menu.items', $items)->call('save')->assertHasNoFormErrors();

        $saved = config('gaiacp.client_area.menu');
        $this->assertSame(['type' => 'page', 'key' => 'Order'], $saved['items'][0]);
        $this->assertSame(['type' => 'link', 'url' => 'https://example.com', 'label' => ['en' => 'Blog'], 'new_tab' => true], end($saved['items']));
        $this->assertContains('Dashboard', $saved['seen']);
        $this->assertNull(config('gaiacp.public_area.menu'));

        // A link needs an address and a name
        $page->set('data.client_area.menu.items.bad', ['type' => 'link', 'data' => ['url' => 'javascript:alert(1)', 'label' => ['en' => 'x']]])
            ->call('save')->assertHasFormErrors(['client_area.menu.items.bad.data.url']);

        Livewire::test(MenuSettings::class)->callAction('reset');
        $this->assertNull(config('gaiacp.client_area.menu'));
    }

    public function test_admins_without_client_area_settings_cant_open_it(): void
    {
        User::factory()->create(); // the first admin is a super admin
        $this->actingAs(User::factory()->limitedTo([AdminArea::Billing])->create());

        $this->get(MenuSettings::getUrl())->assertForbidden();
    }
}
