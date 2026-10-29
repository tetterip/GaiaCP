<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Client\Pages\Order as OrderPage;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\ProductTags\Pages\ManageProductTags;
use App\Models\Client;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\ProductTag;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** A product's own colour on the order pages, picked from the circles shared with product tags. */
class ProductColorsTest extends TestCase
{
    use RefreshDatabase;

    private Product $basic;

    private Product $pro;

    protected function setUp(): void
    {
        parent::setUp();

        $group = ProductGroup::create(['names' => ['en' => 'Shared hosting'], 'features' => [['key' => 'disk', 'names' => ['en' => 'Disk space']]]]);
        $this->basic = Product::factory()->priced(6000)->create(['name' => 'Basic plan', 'product_group_id' => $group->id, 'features' => ['disk' => '10 GB']]);
        $this->pro = Product::factory()->priced(12000)->create(['name' => 'Pro plan', 'product_group_id' => $group->id, 'features' => ['disk' => '50 GB']]);
    }

    public function test_admins_pick_a_colour_or_none_for_a_product(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(EditProduct::class, ['record' => $this->pro->getRouteKey()])
            ->assertSee('aria-label="Purple"', false)
            ->fillForm(['color' => 'purple'])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame('purple', $this->pro->fresh()->orderColor());

        Livewire::test(EditProduct::class, ['record' => $this->pro->getRouteKey()])
            ->fillForm(['color' => 'black'])
            ->call('save')
            ->assertHasFormErrors(['color']);

        Livewire::test(EditProduct::class, ['record' => $this->pro->getRouteKey()])
            ->fillForm(['color' => null])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertNull($this->pro->fresh()->orderColor());
    }

    public function test_tags_are_coloured_with_the_same_circles(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(ManageProductTags::class)
            ->callAction('create', ['names' => ['en' => 'Fast'], 'color' => 'teal'])
            ->assertHasNoFormErrors();
        $this->assertSame('#0d9488', ProductTag::query()->latest('id')->first()->hex());

        Livewire::test(ManageProductTags::class)
            ->callAction('create', ['names' => ['en' => 'Odd'], 'color' => 'black'])
            ->assertHasFormErrors(['color']);
    }

    public function test_clients_see_the_colour_on_the_cards_the_compare_table_and_the_public_home_page(): void
    {
        $this->pro->update(['color' => 'green']);
        $client = Client::factory()->create();
        $user = User::factory()->create(['role' => UserRole::Client, 'client_id' => $client->id, 'email' => $client->email]);

        Filament::setCurrentPanel('client');
        $this->actingAs($user, 'client');
        Livewire::test(OrderPage::class)
            ->set('data.group', (string) $this->pro->product_group_id)
            ->assertSee('<span class="gcp-card-name" data-gcp-color="green">', false)
            ->assertSee('data-gcp-color="green"', false);

        auth('client')->logout();
        config(['gaiacp.public_area.home' => true, 'gaiacp.client_area.public_orders' => true]);
        $this->get('/')->assertOk()->assertSee('class="gcp-card gcp-public-product"  data-gcp-color="green"', false)
            ->assertSee('[data-gcp-color="green"]', false)
            ->assertSee('--gcp-product: #16a34a', false);
    }
}
