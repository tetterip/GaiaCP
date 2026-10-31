<?php

namespace Tests\Feature;

use App\Actions\ClientOrder;
use App\Enums\BillingCycle;
use App\Enums\ServiceType;
use App\Enums\UserRole;
use App\Filament\Client\Pages\Order as OrderPage;
use App\Filament\Client\Pages\PublicOrder;
use App\Filament\Resources\ProductGroups\Pages\ManageProductGroups;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Models\Client;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\Registrar;
use App\Models\User;
use App\Support\OrderCatalogue;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Settings → Product groups: the order pages, the public home page and its menu show products by group, with icons and a compare table. */
class ProductGroupsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private ProductGroup $shared;

    private ProductGroup $vps;

    private Product $basic;

    private Product $pro;

    protected function setUp(): void
    {
        parent::setUp();

        User::factory()->create(['email' => 'admin@example.com']);
        $client = Client::factory()->create();
        $this->user = User::factory()->create(['role' => UserRole::Client, 'client_id' => $client->id, 'email' => $client->email]);
        Registrar::factory()->withTlds()->create();

        $this->shared = ProductGroup::create([
            'names' => ['en' => 'Shared hosting', 'el' => 'Φιλοξενία'],
            'descriptions' => ['en' => 'Websites and email.'],
            'icon' => 'server',
            'sort' => 1,
            'features' => [
                ['key' => 'disk', 'names' => ['en' => 'Disk space', 'el' => 'Χώρος']],
                ['key' => 'backup', 'names' => ['en' => 'Daily backup']],
            ],
        ]);
        $this->vps = ProductGroup::create(['slug' => 'vps', 'names' => ['en' => 'VPS'], 'icon' => 'cloud', 'sort' => 2]);

        // The dearer one first by name, so the order by price shows
        $this->pro = Product::factory()->priced(12000)->create(['name' => 'Alpha Pro', 'product_group_id' => $this->shared->id, 'features' => ['disk' => '50 GB', 'backup' => 'Yes']]);
        $this->basic = Product::factory()->priced(6000)->create(['name' => 'Zeta Basic', 'product_group_id' => $this->shared->id, 'features' => ['disk' => '10 GB', 'backup' => 'No']]);
        Product::factory()->priced(900)->create(['name' => 'VPS 2 GB', 'type' => ServiceType::Other, 'product_group_id' => $this->vps->id, 'package' => null]);
    }

    private function page(array $query = [], ?string $group = null): mixed
    {
        Filament::setCurrentPanel('client');
        $this->actingAs($this->user, 'client');

        return Livewire::withQueryParams($query)->test(OrderPage::class, ['group' => $group]);
    }

    public function test_the_catalogue_lists_groups_in_order_with_their_products_cheapest_first(): void
    {
        Product::factory()->create(['name' => 'Loose product']);
        $hidden = ProductGroup::create(['slug' => 'secret', 'names' => ['en' => 'Secret'], 'hidden' => true]);
        Product::factory()->create(['name' => 'Secret plan', 'product_group_id' => $hidden->id]);

        $groups = OrderCatalogue::groups();
        $this->assertSame(['shared-hosting', 'vps', OrderCatalogue::OTHER, OrderCatalogue::DOMAINS], $groups->keys()->all());
        $this->assertSame(['Zeta Basic', 'Alpha Pro'], $groups['shared-hosting']['products']->pluck('name')->all());
        $this->assertSame(['Loose product'], $groups[OrderCatalogue::OTHER]['products']->pluck('name')->all());
        $this->assertSame('Other', $groups[OrderCatalogue::OTHER]['name']);

        // A hidden group only by its link
        $this->assertArrayHasKey('secret', OrderCatalogue::groups('secret'));
        $this->assertSame('secret', OrderCatalogue::keyFor(Product::query()->where('name', 'Secret plan')->value('id')));
        $this->assertSame(OrderCatalogue::DOMAINS, OrderCatalogue::keyFor(ClientOrder::DOMAIN_ONLY));
    }

    public function test_the_order_page_asks_for_the_group_first(): void
    {
        $page = $this->page()
            ->assertSee('Shared hosting')->assertSee('VPS')->assertSee(__('Domain names'))
            ->assertSee(__('From :price', ['price' => '€5.00 / month']))
            ->assertDontSee('Zeta Basic');

        $page->fillForm(['group' => 'shared-hosting'])
            ->assertSee('Zeta Basic')->assertSee('Alpha Pro')->assertDontSee('VPS 2 GB')
            // The compare table
            ->assertSee(__('Compare packages'))->assertSeeInOrder(['Disk space', '10 GB', '50 GB', 'Daily backup']);

        $page->fillForm(['group' => 'vps'])->assertSee('VPS 2 GB')->assertDontSee('Zeta Basic')->assertDontSee(__('Compare packages'));

        // Domain names go straight to the domain
        $page->fillForm(['group' => OrderCatalogue::DOMAINS])->assertSet('data.product', ClientOrder::DOMAIN_ONLY)->assertSee(__('Register a new domain'));
    }

    public function test_the_compare_table_shows_each_cycle_s_price_a_popular_badge_and_choose_buttons(): void
    {
        $this->pro->update([
            'popular' => true,
            'prices' => [
                BillingCycle::Monthly->value => ['enabled' => true, 'price_cents' => 1100, 'setup_cents' => 500],
                BillingCycle::Annually->value => ['enabled' => true, 'price_cents' => 12000],
            ],
        ]);

        $page = $this->page()->fillForm(['group' => 'shared-hosting'])
            ->assertSee(__('Popular'))
            // Monthly only for Pro (Basic shows a dash), Annually for both, after the features
            // (the update's HTML comes back as JSON, so the euro sign is left out)
            ->assertSeeInOrder(['Daily backup', BillingCycle::Monthly->getLabel(), '11.00', '5.00 setup', BillingCycle::Annually->getLabel(), '60.00', '120.00'])
            ->assertSee(__('Choose'));

        // Choose picks the product as its card does: its default cycle follows. The table carries the chosen product,
        // whose change gives it a sideways nudge on a phone (a hint that it scrolls)
        $page->assertSeeHtml('data-chosen=""')
            ->set('data.product', (string) $this->pro->id)
            ->assertSet('data.billing_cycle', BillingCycle::Annually->value)
            ->assertSee(__('Chosen'))
            ->assertSeeHtml('data-chosen="'.$this->pro->id.'"');

        // Products not marked Popular carry no badge
        $this->pro->update(['popular' => false]);
        $this->page()->fillForm(['group' => 'shared-hosting'])->assertDontSee(__('Popular'));
    }

    public function test_the_admin_marks_a_product_popular(): void
    {
        Filament::setCurrentPanel('admin');
        $this->actingAs(User::query()->where('email', 'admin@example.com')->first());

        Livewire::test(EditProduct::class, ['record' => $this->basic->getRouteKey()])
            ->fillForm(['popular' => true])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue($this->basic->fresh()->popular);
    }

    public function test_links_open_a_group_or_a_product(): void
    {
        $this->page([], 'vps')->assertSet('data.group', 'vps')->assertSee('VPS 2 GB');
        $this->page(['product' => (string) $this->pro->id])->assertSet('data.group', 'shared-hosting')->assertSet('data.product', (string) $this->pro->id);

        $this->actingAs($this->user, 'client')->get('/order/vps')->assertOk();
        $this->get('/order/'.OrderCatalogue::DOMAINS)->assertOk();

        // The public order page has the same links, and sends clients to theirs
        config(['gaiacp.client_area.public_orders' => true]);
        $this->get('/cart/shared-hosting')->assertRedirect('/order/shared-hosting');
        auth('client')->logout();
        $this->get('/cart/shared-hosting')->assertOk()->assertSee('Zeta Basic');
        Filament::setCurrentPanel('client');
        Livewire::withQueryParams([])->test(PublicOrder::class, ['group' => 'vps'])->assertSet('data.group', 'vps');
    }

    public function test_an_order_from_a_group_is_placed_as_before(): void
    {
        $this->page()
            ->fillForm(['group' => 'shared-hosting'])
            ->fillForm(['product' => (string) $this->basic->id, 'billing_cycle' => 'annually', 'domain_option' => 'own', 'domain' => 'maria.gr'])
            ->call('addToCart')
            ->assertHasNoFormErrors();

        $this->assertSame(1, ClientOrder::cartCount());
    }

    public function test_the_public_home_page_and_menu_show_the_groups(): void
    {
        config(['gaiacp.public_area.home' => true, 'gaiacp.client_area.public_orders' => true]);

        $this->get('/')->assertOk()
            ->assertSeeInOrder(['Shared hosting', 'Websites and email.', 'Zeta Basic', 'Alpha Pro', 'VPS', 'VPS 2 GB'])
            ->assertSee('/cart/shared-hosting?product='.$this->basic->id, false)
            // A line for each group under Order in the side menu
            ->assertSee('/cart/vps"', false);
    }

    public function test_admins_manage_groups_and_a_products_place_in_them(): void
    {
        $this->actingAs(User::query()->where('email', 'admin@example.com')->sole());

        Livewire::test(ManageProductGroups::class)
            ->callAction('create', [
                'names' => ['en' => 'Email hosting'],
                'icon' => 'envelope',
                'features' => [['names' => ['en' => 'Mailboxes']]],
            ])
            ->assertHasNoFormErrors();

        $group = ProductGroup::query()->where('slug', 'email-hosting')->sole();
        $key = $group->features[0]['key'];
        $this->assertSame(['Mailboxes'], array_values($group->featureNames()));

        // A renamed feature keeps its key, so the values stay
        $group->update(['features' => [['key' => $key, 'names' => ['en' => 'Email accounts']]]]);
        $this->assertSame([$key => 'Email accounts'], $group->fresh()->featureNames());

        Livewire::test(EditProduct::class, ['record' => $this->basic->id])
            ->fillForm(['product_group_id' => $group->id, 'icon' => 'envelope'])
            ->fillForm(["features.{$key}" => '5'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->basic->refresh();
        $this->assertSame([$group->id, 'envelope', '5'], [$this->basic->product_group_id, $this->basic->icon, $this->basic->feature($key)]);

        // Deleting a group keeps its products, under Other
        Livewire::test(ManageProductGroups::class)->callAction(TestAction::make('delete')->table($group));
        $this->assertNull($this->basic->fresh()->product_group_id);
    }
}
