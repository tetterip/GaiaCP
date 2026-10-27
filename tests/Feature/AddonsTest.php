<?php

namespace Tests\Feature;

use App\Actions\AddAddon;
use App\Actions\ClientOrder;
use App\Actions\PlaceOrder;
use App\Actions\TransferService;
use App\Enums\BillingCycle;
use App\Enums\ServiceStatus;
use App\Enums\ServiceType;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Services\Pages\EditService;
use App\Filament\Resources\Services\RelationManagers\AddonsRelationManager;
use App\Invoices\GenerateInvoices;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/** Add-ons: products sold on top of a service, on its cycle and due date, following its status. */
class AddonsTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private Product $hosting;

    private Product $backup;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $this->client = Client::factory()->create();
        $this->hosting = Product::factory()->create(['name' => 'Hosting', 'type' => ServiceType::Other, 'package' => null]);
        $this->backup = Product::factory()->create([
            'name' => 'Daily backup', 'type' => ServiceType::Other, 'package' => null, 'addon' => true,
            'prices' => ['annually' => ['enabled' => true, 'price_cents' => 2400, 'setup_cents' => 500]],
        ]);
        $this->backup->addonFor()->attach($this->hosting);
    }

    private function service(array $attributes = []): Service
    {
        return Service::factory()->for($this->client)->create($attributes + [
            'type' => ServiceType::Other, 'product_id' => $this->hosting->id, 'server_id' => null, 'label' => 'Hosting',
            'price_cents' => 6000, 'billing_cycle' => BillingCycle::Annually, 'next_due_date' => '2026-12-01', 'status' => ServiceStatus::Active,
        ]);
    }

    public function test_add_ons_are_never_ordered_on_their_own(): void
    {
        Product::factory()->create(['name' => 'SSL', 'addon' => true, 'type' => ServiceType::Other, 'package' => null]);

        $this->assertNotContains('Daily backup', Product::query()->orderable()->pluck('name')->all());
        $this->assertNotContains('Daily backup', ClientOrder::products()->pluck('name')->all());
        // One naming products fits only those; one naming none fits any.
        $this->assertSame(['Daily backup', 'SSL'], Product::query()->addonsFor($this->hosting)->orderBy('name')->pluck('name')->all());
        $this->assertSame(['SSL'], Product::query()->addonsFor(Product::factory()->create(['name' => 'Other']))->pluck('name')->all());
    }

    public function test_an_add_on_shares_the_cycle_and_due_date_and_lands_on_the_same_notice(): void
    {
        $this->travelTo('2026-10-23');
        $this->actingAs(User::factory()->create());
        $service = $this->service();

        Livewire::test(AddonsRelationManager::class, ['ownerRecord' => $service, 'pageClass' => EditService::class])
            ->callAction(TestAction::make('addAddon')->table(), ['product_id' => $this->backup->id, 'label' => 'Daily backup', 'price' => '24.00'])
            ->assertHasNoFormErrors();

        $addon = $service->addons()->sole();
        $this->assertSame(BillingCycle::Annually, $addon->billing_cycle);
        $this->assertSame('2026-12-01', $addon->next_due_date->toDateString());
        $this->assertSame(ServiceStatus::Active, $addon->status);
        $this->assertSame(2900, $addon->first_payment_cents, 'its setup fee with its first payment');

        app(GenerateInvoices::class)->handle(today()->setDate(2026, 11, 20));
        $notice = Invoice::query()->sole();
        $this->assertEqualsCanonicalizing([$service->id, $addon->id], $notice->items()->pluck('service_id')->all());
        $this->assertSame(6000 + 2900, $notice->total_cents);
    }

    public function test_on_a_pending_order_it_joins_the_order(): void
    {
        $order = app(PlaceOrder::class)->handle($this->client, [[
            'type' => ServiceType::Other, 'product_id' => $this->hosting->id, 'label' => 'Hosting', 'price_cents' => 6000, 'billing_cycle' => BillingCycle::Annually,
        ]]);

        $addon = app(AddAddon::class)->handle($order->services()->sole(), $this->backup);

        $this->assertSame(ServiceStatus::Pending, $addon->status);
        $this->assertSame($order->id, $addon->order_id);
        $this->assertSame(2400, $addon->price_cents);
    }

    public function test_it_follows_the_service(): void
    {
        $service = $this->service();
        $addon = app(AddAddon::class)->handle($service, $this->backup);

        $service->update(['status' => ServiceStatus::Suspended, 'suspension_reason' => Service::SUSPENDED_OVERDUE]);
        $this->assertSame(ServiceStatus::Suspended, $addon->fresh()->status);
        $this->assertSame(Service::SUSPENDED_OVERDUE, $addon->fresh()->suspension_reason);

        $service->update(['status' => ServiceStatus::Active, 'suspension_reason' => null]);
        $this->assertSame(ServiceStatus::Active, $addon->fresh()->status);

        $service->update(['auto_terminate' => true]);
        $this->assertTrue($addon->fresh()->auto_terminate);

        $service->update(['status' => ServiceStatus::Terminated]);
        $this->assertSame(ServiceStatus::Terminated, $addon->fresh()->status);

        $this->expectException(\InvalidArgumentException::class);
        app(AddAddon::class)->handle($service, $this->backup);
    }

    public function test_it_moves_with_the_service(): void
    {
        $service = $this->service();
        $addon = app(AddAddon::class)->handle($service, $this->backup);
        $other = Client::factory()->create();

        app(TransferService::class)->handle($service, $other);

        $this->assertSame($other->id, $addon->fresh()->client_id);
    }

    public function test_the_product_form_marks_an_add_on_and_what_it_fits(): void
    {
        $this->actingAs(User::factory()->create());
        $ssl = Product::factory()->create(['name' => 'SSL', 'type' => ServiceType::Other, 'package' => null]);

        Livewire::test(EditProduct::class, ['record' => $ssl->getRouteKey()])
            ->fillForm(['addon' => true, 'addonFor' => [$this->hosting->id]])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue($ssl->fresh()->addon);
        $this->assertSame([$this->hosting->id], $ssl->addonFor()->pluck('products.id')->all());
    }
}
