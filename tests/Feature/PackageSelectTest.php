<?php

namespace Tests\Feature;

use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Models\Product;
use App\Models\Server;
use App\Models\User;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\FakesWhm;
use Tests\TestCase;

class PackageSelectTest extends TestCase
{
    use FakesWhm, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeWhm();
        $this->actingAs(User::factory()->create());
    }

    public function test_package_is_a_dropdown_of_the_servers_packages(): void
    {
        $server = Server::factory()->create();

        Livewire::test(CreateProduct::class)
            ->fillForm(['type' => 'hosting', 'servers' => [$server->id]])
            ->assertFormFieldExists('package', fn (Select $field) => $field->getOptions() === ['basic' => 'basic', 'pro' => 'pro']);
    }

    public function test_a_package_the_server_does_not_list_stays_selectable(): void
    {
        $product = Product::factory()->create(['package' => 'legacy']);
        $product->servers()->attach(Server::factory()->create());

        Livewire::test(EditProduct::class, ['record' => $product->id])
            ->assertFormFieldExists('package', fn (Select $field) => array_key_exists('legacy', $field->getOptions()) && $field->isSearchable());
    }
}
