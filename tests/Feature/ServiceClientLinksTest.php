<?php

namespace Tests\Feature;

use App\Enums\AdminArea;
use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\Services\Pages\EditService;
use App\Filament\Resources\Services\Pages\ListServices;
use App\Filament\Resources\Services\ServiceResource;
use App\Models\Client;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ServiceClientLinksTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_service_page_sits_under_its_client_and_links_to_them(): void
    {
        $client = Client::factory()->create(['first_name' => 'Maria', 'last_name' => 'Papadopoulou']);
        $service = Service::factory()->for($client)->create(['label' => 'VPS Small', 'server_id' => null]);
        $domain = Service::factory()->domain()->for($client)->create();
        $this->actingAs(User::factory()->create());

        $hostingTab = ClientResource::getUrl('edit', ['record' => $client, 'relation' => 'hosting']);
        $this->assertSame($hostingTab, ServiceResource::clientUrl($service));
        $this->assertSame(ClientResource::getUrl('edit', ['record' => $client, 'relation' => 'domains']), ServiceResource::clientUrl($domain));

        $crumbs = Livewire::test(EditService::class, ['record' => $service->getKey()])->instance()->getBreadcrumbs();
        $this->assertSame([
            ClientResource::getUrl('index') => 'Clients',
            ClientResource::getUrl('edit', ['record' => $client]) => 'Papadopoulou',
            $hostingTab => 'Hosting',
            ServiceResource::getUrl('edit', ['record' => $service]) => 'VPS Small',
            0 => 'Edit',
        ], $crumbs);

        $this->get(ServiceResource::getUrl('edit', ['record' => $service]))->assertOk()
            ->assertSee('Open client')
            ->assertSee(e($hostingTab), false);
    }

    public function test_the_services_list_links_the_client_to_their_page(): void
    {
        $service = Service::factory()->create();
        $this->actingAs(User::factory()->create());

        Livewire::withQueryParams(['tab' => 'hosting'])->test(ListServices::class)
            ->assertSee(e(ServiceResource::clientUrl($service)), false);
    }

    public function test_an_admin_without_the_clients_area_gets_no_client_links(): void
    {
        $service = Service::factory()->create(['label' => 'VPS Small', 'server_id' => null]);
        User::factory()->create(); // the first admin is always a super admin
        $this->actingAs(User::factory()->limitedTo([AdminArea::Services])->create());

        $this->assertNull(ServiceResource::clientUrl($service));
        $crumbs = Livewire::test(EditService::class, ['record' => $service->getKey()])->instance()->getBreadcrumbs();
        $this->assertNotContains('Clients', $crumbs);
        $this->get(ServiceResource::getUrl('edit', ['record' => $service]))->assertOk()->assertDontSee('Open client');
    }
}
