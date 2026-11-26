<?php

namespace Tests\Feature;

use App\Actions\CompareServerAccounts;
use App\Actions\ServiceLifecycle;
use App\Filament\Resources\Services\Pages\EditService;
use App\Models\Product;
use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use App\Provisioning\PackageOptions;
use App\Provisioning\ProvisionerFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\FakesHestia;
use Tests\FakesPlesk;
use Tests\FakesWhm;
use Tests\TestCase;

/** Moving a hosting account to a bigger plan: the label and package follow the product, the panel gets the package, sizes show. */
class HostingPlanChangeTest extends TestCase
{
    use FakesHestia, FakesPlesk, FakesWhm, RefreshDatabase;

    private Product $small;

    private Product $big;

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    private function cpanelService(array $attributes = []): Service
    {
        $this->fakeWhm();
        $this->small = Product::factory()->create(['name' => 'Pirgi Plan (5GB)', 'package' => 'basic']);
        $this->big = Product::factory()->create(['name' => 'Didima Plan (10GB)', 'package' => 'pro']);
        $this->service = Service::factory()->create(['product_id' => $this->small->id, 'label' => 'Pirgi Plan (5GB)', 'package' => 'basic', ...$attributes]);
        $this->whmAccounts[$this->service->username] = false;

        return $this->service;
    }

    /** @return list<array<string, mixed>> */
    private function changepackageCalls(): array
    {
        return array_values(array_map(fn ($call) => $call[1], array_filter($this->whmCalls, fn ($call) => $call[0] === 'changepackage')));
    }

    public function test_another_product_brings_its_name_and_package_and_saving_sets_the_package_on_the_server(): void
    {
        $service = $this->cpanelService();

        Livewire::test(EditService::class, ['record' => $service->getRouteKey()])
            ->fillForm(['product_id' => $this->big->id])
            ->assertSchemaStateSet(['label' => 'Didima Plan (10GB)', 'package' => 'pro'])
            ->call('save')
            ->assertHasNoFormErrors();

        $service->refresh();
        $this->assertSame('Didima Plan (10GB)', $service->label);
        $this->assertSame('pro', $service->package);
        $this->assertSame([['user' => $service->username, 'pkg' => 'pro']], $this->changepackageCalls());
    }

    public function test_a_label_of_its_own_is_kept(): void
    {
        $service = $this->cpanelService(['label' => 'Shop hosting']);

        Livewire::test(EditService::class, ['record' => $service->getRouteKey()])
            ->fillForm(['product_id' => $this->big->id])
            ->assertSchemaStateSet(['label' => 'Shop hosting', 'package' => 'pro']);
    }

    public function test_nothing_is_saved_when_the_server_refuses_the_package(): void
    {
        $service = $this->cpanelService();
        $this->whmFailures['changepackage'] = 'No such package';

        Livewire::test(EditService::class, ['record' => $service->getRouteKey()])
            ->fillForm(['product_id' => $this->big->id])
            ->call('save')
            ->assertNotified(__('Not saved: the server did not take the new package'));

        $service->refresh();
        $this->assertSame('basic', $service->package);
        $this->assertSame($this->small->id, $service->product_id);
    }

    public function test_a_pending_service_only_saves_the_package(): void
    {
        $service = $this->cpanelService(['status' => 'pending']);

        Livewire::test(EditService::class, ['record' => $service->getRouteKey()])
            ->fillForm(['package' => 'pro'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('pro', $service->fresh()->package);
        $this->assertSame([], $this->changepackageCalls());
    }

    public function test_change_package_tells_the_server_even_when_the_service_already_names_it(): void
    {
        // Edited in the form while the server stayed on the old one
        $service = $this->cpanelService(['package' => 'pro']);

        app(ServiceLifecycle::class)->changePackage($service, 'pro');

        $this->assertSame([['user' => $service->username, 'pkg' => 'pro']], $this->changepackageCalls());
    }

    public function test_package_lists_show_the_disk_and_bandwidth(): void
    {
        $this->cpanelService();

        $this->assertSame([
            'basic' => 'basic · 1 GB disk · 10 GB bandwidth',
            'pro' => 'pro · unlimited disk · unlimited bandwidth',
        ], PackageOptions::labels($this->service->server_id));
    }

    public function test_plesk_and_hestia_packages_have_sizes_too(): void
    {
        $this->fakePlesk();
        $plesk = Server::factory()->plesk()->create();
        $this->assertSame([
            'Starter' => 'Starter · 2 GB disk · unlimited bandwidth',
            'Business' => 'Business · unlimited disk · unlimited bandwidth',
        ], PackageOptions::labels($plesk->id));

        $this->fakeHestia();
        $hestia = Server::factory()->hestia()->create();
        $this->assertSame('Starter · unlimited disk · unlimited bandwidth', PackageOptions::labels($hestia->id)['Starter']);
    }

    public function test_a_reseller_package_is_found_by_its_name_without_the_prefix(): void
    {
        $this->fakeWhm();
        $this->whmPackages = [['name' => 'default'], ['name' => 'ttrpgr_pyrgi', 'QUOTA' => '5120', 'BWLIMIT' => 'unlimited']];
        $server = Server::factory()->create(['username' => 'ttrpgr']);
        $panel = app(ProvisionerFactory::class)->forServer($server);

        $this->assertSame('ttrpgr_pyrgi', $panel->panelPackage('pyrgi'));
        $this->assertSame('ttrpgr_pyrgi', $panel->panelPackage('ttrpgr_pyrgi'));
        $this->assertSame('default', $panel->panelPackage('default'));

        $service = Service::factory()->create(['server_id' => $server->id, 'package' => 'other']);
        $this->whmAccounts[$service->username] = false;
        app(ServiceLifecycle::class)->changePackage($service, 'pyrgi');

        $this->assertSame([['user' => $service->username, 'pkg' => 'ttrpgr_pyrgi']], $this->changepackageCalls());
        $this->assertSame('pyrgi', $service->fresh()->package);
    }

    public function test_a_product_on_two_reseller_servers_offers_the_packages_both_have_without_the_prefix(): void
    {
        $this->fakeWhm();
        // The fake answers the same list on every server
        $this->whmPackages = [['name' => 'default'], ['name' => 'ttrpgr_pyrgi', 'QUOTA' => '5120', 'BWLIMIT' => 'unlimited'], ['name' => 'ttrpeu_pyrgi', 'QUOTA' => '5120', 'BWLIMIT' => 'unlimited']];
        $gr = Server::factory()->create(['username' => 'ttrpgr']);
        $eu = Server::factory()->create(['username' => 'ttrpeu']);

        $this->assertSame(['default' => 'default', 'pyrgi' => 'pyrgi · 5 GB disk · unlimited bandwidth'], PackageOptions::commonLabels([$gr->id, $eu->id]));
        $this->assertArrayHasKey('ttrpgr_pyrgi', PackageOptions::commonLabels([$gr->id]), 'one server lists its own names');
    }

    public function test_compare_with_the_server_takes_the_reseller_prefix_as_the_same_package(): void
    {
        $this->fakeWhm();
        $server = Server::factory()->create(['username' => 'ttrpgr']);
        $service = Service::factory()->create(['server_id' => $server->id, 'username' => 'maria', 'domain' => 'maria.example', 'package' => 'pyrgi']);
        $this->whmAccounts = ['maria' => false];
        $this->whmPlans = ['maria' => 'ttrpgr_pyrgi'];

        $result = app(CompareServerAccounts::class)->handle($server);

        $this->assertSame(1, $result['matching'], 'not reported as another package');
        $this->assertTrue($service->exists);
    }
}
