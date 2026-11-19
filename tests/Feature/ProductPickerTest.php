<?php

namespace Tests\Feature;

use App\Actions\ClientOrder;
use App\Enums\ServiceType;
use App\Enums\UserRole;
use App\Filament\Client\Resources\Services\Pages\ListServices;
use App\Livewire\ProductPicker;
use App\Models\Client;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\Registrar;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** New hosting / New service over the client's services lists: a product goes in the cart without leaving the list. */
class ProductPickerTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private Product $basic;

    private Product $vps;

    private Product $maintenance;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = Client::factory()->create();
        $this->actingAs(User::factory()->create(['role' => UserRole::Client, 'client_id' => $this->client->id, 'email' => $this->client->email]), 'client');
        Filament::setCurrentPanel('client');

        $shared = ProductGroup::query()->create(['names' => ['en' => 'Shared'], 'slug' => 'shared']);
        $servers = ProductGroup::query()->create(['names' => ['en' => 'VPS'], 'slug' => 'vps']);
        $prices = ['monthly' => ['enabled' => true, 'price_cents' => 800, 'setup_cents' => 0], 'annually' => ['enabled' => true, 'price_cents' => 9000, 'setup_cents' => 500]];
        $this->basic = Product::factory()->create(['name' => 'Basic', 'prices' => $prices, 'product_group_id' => $shared->id]);
        $this->vps = Product::factory()->create(['name' => 'VPS One', 'prices' => $prices, 'product_group_id' => $servers->id]);
        $this->maintenance = Product::factory()->create(['name' => 'Care plan', 'type' => ServiceType::Other, 'prices' => $prices]);
        Registrar::factory()->withTlds()->create();
    }

    public function test_hosting_lists_its_groups_and_their_products(): void
    {
        $picker = Livewire::test(ProductPicker::class, ['kind' => 'hosting']);

        $this->assertSame(['shared', 'vps'], $picker->instance()->groups()->keys()->all());
        $picker->assertSee(['Shared', 'VPS', 'Basic', 'Monthly · €8.00', 'Annually · €90.00 + €5.00 setup'])->assertDontSee(['VPS One', 'Care plan']);
        $picker->call('pickGroup', 'vps')->assertSee('VPS One')->assertDontSee('Basic');

        // Other services: no hosting there
        Livewire::test(ProductPicker::class, ['kind' => 'other'])->assertSee('Care plan')->assertDontSee(['Basic', 'VPS One']);
    }

    public function test_hosting_asks_for_its_domain_then_goes_in_the_cart(): void
    {
        $picker = Livewire::test(ProductPicker::class, ['kind' => 'hosting'])
            ->set('cycles.'.$this->basic->id, 'annually')
            ->call('add', $this->basic->id)
            ->assertSet('pending', $this->basic->id)
            ->assertNotDispatched('product-added');
        $this->assertSame([], ClientOrder::cart());

        $picker->call('add', $this->basic->id)->assertHasErrors('domain')->assertNotDispatched('product-added');

        $picker->set('domain', 'myshop.gr')->set('domainOption', 'register')->call('add', $this->basic->id)
            ->assertHasNoErrors()
            ->assertDispatched('product-added')
            ->assertDispatched('cart-updated')
            ->assertSet('pending', null);

        $line = array_values(ClientOrder::cart())[0];
        $this->assertSame(['product' => (string) $this->basic->id, 'billing_cycle' => 'annually', 'domain_option' => 'register', 'domain' => 'myshop.gr', 'years' => 1], $line);
    }

    public function test_a_product_without_a_domain_goes_in_at_once(): void
    {
        Livewire::test(ProductPicker::class, ['kind' => 'other'])
            ->call('add', $this->maintenance->id)
            ->assertDispatched('product-added');

        $this->assertSame((string) $this->maintenance->id, array_values(ClientOrder::cart())[0]['product']);

        // Not one of this kind: nothing happens
        Livewire::test(ProductPicker::class, ['kind' => 'other'])->call('add', $this->basic->id)->assertNotDispatched('product-added');
        $this->assertCount(1, ClientOrder::cart());
    }

    public function test_the_list_opens_the_window_and_shows_the_cart_after_adding(): void
    {
        Livewire::withQueryParams(['kind' => 'hosting'])->test(ListServices::class)
            ->assertActionVisible('newService')
            ->assertActionHasLabel('newService', __('New hosting'))
            ->mountAction('newService')
            ->assertSeeLivewire(ProductPicker::class)
            ->dispatch('product-added')
            ->assertDispatched('open-cart');

        config(['gaiacp.client_area.orders' => false]);
        Livewire::withQueryParams(['kind' => 'hosting'])->test(ListServices::class)->assertActionHidden('newService');
    }
}
