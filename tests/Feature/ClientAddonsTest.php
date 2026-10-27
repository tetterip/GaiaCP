<?php

namespace Tests\Feature;

use App\Actions\ClientAddons;
use App\Actions\RecordInvoicePayment;
use App\Credit\ApplyCredit;
use App\Credit\ClientCredit;
use App\Enums\BillingCycle;
use App\Enums\CreditType;
use App\Enums\InvoiceStatus;
use App\Enums\ServiceStatus;
use App\Enums\ServiceType;
use App\Enums\UserRole;
use App\Filament\Client\Resources\Services\Pages\ViewService;
use App\Invoices\GenerateInvoices;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/** Clients add add-ons to a running service: a notice for the days until its due date, active once paid. */
class ClientAddonsTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private User $user;

    private User $admin;

    private Product $backup;

    private Service $hosting;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->travelTo('2026-10-23');

        $this->admin = User::factory()->create(['email' => 'admin@example.com']);
        $this->client = Client::factory()->create();
        $this->user = User::factory()->create(['role' => UserRole::Client, 'client_id' => $this->client->id, 'email' => $this->client->email]);
        $product = Product::factory()->create(['name' => 'Hosting', 'type' => ServiceType::Other, 'package' => null]);
        $this->backup = Product::factory()->create([
            'name' => 'Daily backup', 'type' => ServiceType::Other, 'package' => null, 'addon' => true,
            'prices' => ['annually' => ['enabled' => true, 'price_cents' => 3650, 'setup_cents' => 500]],
        ]);
        $this->backup->addonFor()->attach($product);
        $this->hosting = Service::factory()->for($this->client)->create([
            'type' => ServiceType::Other, 'product_id' => $product->id, 'server_id' => null, 'label' => 'Hosting', 'domain' => 'maria.gr',
            'price_cents' => 6000, 'billing_cycle' => BillingCycle::Annually, 'start_date' => '2025-12-01', 'next_due_date' => '2026-12-01',
            'status' => ServiceStatus::Active,
        ]);
    }

    private function clientPage(): Testable
    {
        Filament::setCurrentPanel('client');
        $this->actingAs($this->user, 'client');

        return Livewire::test(ViewService::class, ['record' => $this->hosting->getRouteKey()]);
    }

    public function test_the_client_adds_one_and_it_is_active_once_its_notice_is_paid(): void
    {
        // 39 of the year's 365 days, and the setup fee
        $this->assertSame(['days' => 390, 'setup' => 500, 'total' => 890], ClientAddons::quote($this->hosting, $this->backup));

        $this->clientPage()
            ->callAction('orderAddon', ['product_id' => $this->backup->id])
            ->assertHasNoFormErrors();

        $addon = $this->hosting->addons()->sole();
        $this->assertSame(ServiceStatus::Pending, $addon->status);
        $this->assertSame('Daily backup', $addon->label);
        $this->assertSame(3650, $addon->price_cents);
        $this->assertSame('2026-12-01', $addon->next_due_date->toDateString());
        $this->assertNull($addon->order_id);

        $item = InvoiceItem::query()->where('service_id', $addon->id)->sole();
        $this->assertSame(InvoiceItem::UPGRADE, $item->fee);
        $this->assertSame(890, $item->amount_cents);
        $this->assertSame('2026-10-23', $item->invoice->due_date->toDateString());
        $this->assertNotNull($item->invoice->fresh()->emailed_at);

        // The admins' bell
        $this->assertSame(1, $this->admin->notifications()->count());
        $this->assertStringContainsString('Daily backup', $this->admin->notifications()->first()->data['body']);

        // Not offered twice, and the notice is linked from the service's page
        $this->assertFalse(ClientAddons::canAdd($this->hosting->fresh()));
        $this->clientPage()->assertActionHidden('orderAddon')->assertSee($item->invoice->number);

        app(RecordInvoicePayment::class)->handle($item->invoice, CarbonImmutable::today(), 'bank');

        $addon->refresh();
        $this->assertSame(ServiceStatus::Active, $addon->status);
        $this->assertSame('2026-12-01', $addon->next_due_date->toDateString());
        $this->assertSame('2026-12-01', $this->hosting->fresh()->next_due_date->toDateString());
        $this->assertSame(InvoiceStatus::Paid, $item->invoice->fresh()->status);

        // It renews with the service, on the same notice.
        app(GenerateInvoices::class)->handle(CarbonImmutable::parse('2026-11-17'));
        $notice = Invoice::query()->whereDate('due_date', '2026-12-01')->sole();
        $this->assertEqualsCanonicalizing([$this->hosting->id, $addon->id], $notice->items()->pluck('service_id')->all());
    }

    public function test_one_with_nothing_to_pay_is_active_at_once(): void
    {
        $this->backup->update(['prices' => ['annually' => ['enabled' => true, 'price_cents' => 0, 'setup_cents' => 0]]]);

        $result = app(ClientAddons::class)->order($this->hosting, $this->backup);

        $this->assertNull($result['invoice']);
        $this->assertSame(ServiceStatus::Active, $result['addon']->status);
        $this->assertSame(0, Invoice::count());
    }

    public function test_only_fitting_add_ons_on_running_services_are_offered(): void
    {
        Product::factory()->create(['name' => 'Hidden add-on', 'type' => ServiceType::Other, 'package' => null, 'addon' => true, 'hidden' => true,
            'prices' => ['annually' => ['enabled' => true, 'price_cents' => 100, 'setup_cents' => 0]]]);
        Product::factory()->create(['name' => 'Monthly only', 'type' => ServiceType::Other, 'package' => null, 'addon' => true,
            'prices' => ['monthly' => ['enabled' => true, 'price_cents' => 100, 'setup_cents' => 0]]]);

        $this->assertSame(['Daily backup'], ClientAddons::products($this->hosting)->pluck('name')->all());

        config(['gaiacp.client_area.orders' => false]);
        $this->assertFalse(ClientAddons::canAdd($this->hosting));
        config(['gaiacp.client_area.orders' => true]);

        $this->hosting->update(['auto_terminate' => true]);
        $this->assertFalse(ClientAddons::canAdd($this->hosting));
        $this->hosting->update(['auto_terminate' => false, 'status' => ServiceStatus::Suspended]);
        $this->assertFalse(ClientAddons::canAdd($this->hosting));

        $this->expectException(ValidationException::class);
        app(ClientAddons::class)->order($this->hosting, $this->backup);
    }

    public function test_paying_with_credit_activates_it_too(): void
    {
        config(['gaiacp.credit.enabled' => true]);
        $result = app(ClientAddons::class)->order($this->hosting, $this->backup);
        app(ClientCredit::class)->record($this->client, CreditType::Deposit, 5000, 'Test');

        app(ApplyCredit::class)->toInvoice($result['invoice']->fresh());

        $this->assertSame(ServiceStatus::Active, $result['addon']->fresh()->status);
        $this->assertTrue(ActivityLog::query()->where('event', 'service.activated')->exists());
    }
}
