<?php

namespace Tests\Feature;

use App\Enums\AdminArea;
use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\Clients\Pages\EditClient;
use App\Filament\Resources\Clients\RelationManagers\DomainsRelationManager;
use App\Filament\Resources\Clients\RelationManagers\HostingRelationManager;
use App\Filament\Resources\Clients\RelationManagers\InvoicesRelationManager;
use App\Filament\Resources\Clients\RelationManagers\OtherServicesRelationManager;
use App\Filament\Resources\Clients\RelationManagers\PaymentsRelationManager;
use App\Filament\Resources\Clients\Widgets\ClientSummary;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ClientPageTabsTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = Client::factory()->create(['email' => 'maria@example.com', 'phone' => '+30 210 1234567']);
    }

    public function test_the_page_shows_the_summary_and_a_tab_per_part(): void
    {
        $this->actingAs(User::factory()->create());
        Service::factory()->for($this->client)->create(['domain' => 'shop.gr', 'next_due_date' => today()->addDays(20)]);
        Invoice::create([
            'number' => 'PF-2026-0001', 'client_id' => $this->client->id, 'date' => today()->subDays(20), 'due_date' => today()->subDays(5),
            'currency' => 'EUR', 'subtotal_cents' => 6000, 'tax_cents' => 0, 'total_cents' => 6000,
        ]);

        $this->get(ClientResource::getUrl('edit', ['record' => $this->client]))
            ->assertOk()
            ->assertSeeInOrder([__('Details'), __('Hosting'), __('Domains'), __('Other services'), __('Orders'), __('Payment notices'), __('Payments'), __('Activity')])
            ->assertSee('gcp-client-tabs-select', false);

        Livewire::test(ClientSummary::class, ['record' => $this->client])
            ->assertSee(__('Open balance'))
            ->assertSee(__(':amount past due', ['amount' => '€60.00']))
            ->assertSee('shop.gr')
            ->assertSee('maria@example.com')
            ->assertSee('tel:+302101234567', false);

        $this->assertSame(
            ['', 'hosting', 'domains', 'other', 'orders', 'notices', 'payments', 'tickets', 'activity'],
            array_map('strval', array_keys(Livewire::test(EditClient::class, ['record' => $this->client->getRouteKey()])->instance()->tabOptions())),
        );
    }

    public function test_each_services_tab_lists_only_its_kind_of_this_client(): void
    {
        $this->actingAs(User::factory()->create());
        $hosting = Service::factory()->for($this->client)->create();
        $domain = Service::factory()->domain()->for($this->client)->create();
        $maintenance = Service::factory()->maintenance()->for($this->client)->create();
        $someoneElses = Service::factory()->create();

        $tab = fn (string $manager) => Livewire::test($manager, ['ownerRecord' => $this->client, 'pageClass' => EditClient::class]);

        $tab(HostingRelationManager::class)->assertCanSeeTableRecords([$hosting])->assertCanNotSeeTableRecords([$domain, $maintenance, $someoneElses]);
        $tab(DomainsRelationManager::class)->assertCanSeeTableRecords([$domain])->assertCanNotSeeTableRecords([$hosting, $maintenance]);
        $tab(OtherServicesRelationManager::class)->assertCanSeeTableRecords([$maintenance])->assertCanNotSeeTableRecords([$hosting, $domain]);

        $this->assertSame('1', HostingRelationManager::getBadge($this->client, EditClient::class));
    }

    public function test_the_payments_tab_lists_the_payments_on_the_clients_services(): void
    {
        $this->actingAs(User::factory()->create());
        $mine = Payment::factory()->for(Service::factory()->for($this->client))->create();
        $other = Payment::factory()->create();

        Livewire::test(PaymentsRelationManager::class, ['ownerRecord' => $this->client, 'pageClass' => EditClient::class])
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$other]);
    }

    public function test_an_admin_sees_only_the_tabs_of_their_areas(): void
    {
        User::factory()->create(); // the super admin
        $this->actingAs(User::factory()->limitedTo([AdminArea::Clients])->create());

        $this->assertFalse(HostingRelationManager::canViewForRecord($this->client, EditClient::class));
        $this->assertFalse(InvoicesRelationManager::canViewForRecord($this->client, EditClient::class));
        $this->assertSame(['', 'orders'], array_map('strval', array_keys(Livewire::test(EditClient::class, ['record' => $this->client->getRouteKey()])->instance()->tabOptions())));

        Livewire::test(ClientSummary::class, ['record' => $this->client])
            ->assertDontSee(__('Open balance'))
            ->assertDontSee(__('Next renewal'))
            ->assertSee('maria@example.com');
    }
}
