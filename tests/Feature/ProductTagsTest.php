<?php

namespace Tests\Feature;

use App\Enums\ServiceStatus;
use App\Enums\UserRole;
use App\Filament\Client\Pages\Order as OrderPage;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Filament\Resources\ProductTags\Pages\ManageProductTags;
use App\Filament\Resources\Services\Pages\ListServices;
use App\Models\Client;
use App\Models\ClientMessage;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\ProductTag;
use App\Models\Service;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Settings → Product tags: badges clients see on the order pages, filters for the admin's lists and messages. */
class ProductTagsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Product $basic;

    private Product $pro;

    private ProductTag $ssd;

    private ProductTag $old;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['email' => 'admin@example.com']);
        $group = ProductGroup::create(['names' => ['en' => 'Shared hosting'], 'features' => [['key' => 'disk', 'names' => ['en' => 'Disk space']]]]);
        $this->basic = Product::factory()->priced(6000)->create(['name' => 'Basic plan', 'product_group_id' => $group->id]);
        $this->pro = Product::factory()->priced(12000)->create(['name' => 'Pro plan', 'product_group_id' => $group->id]);

        $this->ssd = ProductTag::create(['names' => ['en' => 'NVMe disks', 'el' => 'Δίσκοι NVMe'], 'color' => 'green']);
        $this->old = ProductTag::create(['names' => ['en' => 'Legacy plan'], 'visible' => false]);
        $this->basic->tags()->attach([$this->ssd->id, $this->old->id]);
    }

    public function test_admins_add_a_tag_and_tick_it_on_a_product(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(ManageProductTags::class)
            ->callAction('create', ['names' => ['en' => 'Brand new'], 'color' => 'blue', 'visible' => true])
            ->assertHasNoFormErrors()
            ->assertSee('Brand new');

        $tag = ProductTag::query()->latest('id')->first();
        $this->assertSame('blue', $tag->color);
        $this->assertSame('#2563eb', $tag->hex());

        Livewire::test(EditProduct::class, ['record' => $this->pro->getRouteKey()])
            ->fillForm(['tags' => [$tag->id]])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame([$tag->id], $this->pro->fresh()->tags->pluck('id')->all());
    }

    public function test_only_visible_tags_are_badges_in_their_language_at_most_three(): void
    {
        $this->assertSame([['name' => 'NVMe disks', 'color' => '#16a34a']], $this->basic->fresh()->badges());

        app()->setLocale('el');
        $this->assertSame('Δίσκοι NVMe', $this->basic->fresh()->badges()[0]['name']);
        $this->assertSame('Legacy plan', $this->old->name(), 'falls back to the default language');
        app()->setLocale('en');

        foreach (range(1, 4) as $i) {
            $this->pro->tags()->attach(ProductTag::create(['names' => ['en' => "Tag {$i}"], 'sort' => $i])->id);
        }
        $this->assertSame(['Tag 1', 'Tag 2', 'Tag 3'], array_column($this->pro->fresh()->badges(), 'name'));
    }

    public function test_clients_see_visible_tags_on_the_order_page_and_the_public_home_page(): void
    {
        $client = Client::factory()->create();
        $user = User::factory()->create(['role' => UserRole::Client, 'client_id' => $client->id, 'email' => $client->email]);
        $this->basic->update(['features' => ['disk' => '10 GB']]);

        Filament::setCurrentPanel('client');
        $this->actingAs($user, 'client');
        Livewire::test(OrderPage::class)
            ->assertSee('NVMe disks')
            ->assertSee('--gcp-tag: #16a34a', false)
            ->assertDontSee('Legacy plan');

        auth('client')->logout();
        config(['gaiacp.public_area.home' => true, 'gaiacp.client_area.public_orders' => true]);
        $this->get('/')->assertOk()->assertSeeInOrder(['Basic plan', 'NVMe disks'])->assertDontSee('Legacy plan');
    }

    public function test_tags_filter_products_services_and_messages_to_clients(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(ListProducts::class)
            ->filterTable('tags', [$this->old->id])
            ->assertCanSeeTableRecords([$this->basic])
            ->assertCanNotSeeTableRecords([$this->pro]);

        $maria = Client::factory()->create();
        $nikos = Client::factory()->create();
        $onBasic = Service::factory()->for($maria)->create(['product_id' => $this->basic->id, 'status' => ServiceStatus::Active]);
        $onPro = Service::factory()->for($nikos)->create(['product_id' => $this->pro->id, 'status' => ServiceStatus::Active]);

        Livewire::test(ListServices::class)
            ->filterTable('product_tags', [$this->ssd->id])
            ->assertCanSeeTableRecords([$onBasic])
            ->assertCanNotSeeTableRecords([$onPro]);

        $message = new ClientMessage(['filters' => ['tags' => [$this->old->id]]]);
        $this->assertSame([$maria->id], $message->clients()->pluck('id')->all());
    }

    public function test_deleting_a_tag_takes_it_off_its_products(): void
    {
        $this->ssd->delete();

        $this->assertSame([$this->old->id], $this->basic->fresh()->tags->pluck('id')->all());
        $this->assertTrue($this->basic->exists);
    }
}
