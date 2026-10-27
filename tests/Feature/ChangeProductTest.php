<?php

namespace Tests\Feature;

use App\Actions\ChangeProduct;
use App\Actions\RecordInvoicePayment;
use App\Credit\ApplyCredit;
use App\Credit\ClientCredit;
use App\Enums\CreditType;
use App\Enums\InvoiceStatus;
use App\Filament\Resources\Services\Pages\EditService;
use App\Invoices\GenerateInvoices;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\FakesWhm;
use Tests\TestCase;

/** Upgrade / downgrade: another product of the same type, the new price from the next due date or the difference now. */
class ChangeProductTest extends TestCase
{
    use FakesWhm, RefreshDatabase;

    private Product $basic;

    private Product $pro;

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->fakeWhm();
        $this->travelTo('2026-10-23');

        $this->basic = Product::factory()->create(['name' => 'Hosting Basic']);
        $this->pro = Product::factory()->priced(12000)->create(['name' => 'Hosting Pro', 'package' => 'pro']);
        $this->service = Service::factory()->create([
            'product_id' => $this->basic->id, 'label' => 'Hosting Basic', 'price_cents' => 6000,
            'start_date' => '2025-12-01', 'next_due_date' => '2026-12-01',
        ]);
        $this->whmAccounts[$this->service->username] = false;
    }

    public function test_the_new_package_now_and_the_new_price_from_the_next_due_date(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(EditService::class, ['record' => $this->service->getRouteKey()])
            ->mountAction('changeProduct')
            ->fillForm(['product_id' => $this->pro->id])
            ->assertSchemaStateSet(['price' => '120.00'])
            ->callMountedAction()
            ->assertHasNoFormErrors();

        $service = $this->service->fresh();
        $this->assertSame($this->pro->id, $service->product_id);
        $this->assertSame('Hosting Pro', $service->label, 'a label that was the product\'s name follows it');
        $this->assertSame(['pro', 12000, '2026-12-01'], [$service->package, $service->price_cents, $service->next_due_date->toDateString()]);
        $this->assertContains(['changepackage', ['user' => $service->username, 'pkg' => 'pro']], $this->whmCalls);
        $this->assertSame(0, Invoice::query()->count(), 'nothing owed now');

        app(GenerateInvoices::class)->handle(today()->setDate(2026, 11, 20));
        $this->assertSame(12000, Invoice::query()->sole()->total_cents);
    }

    public function test_a_notice_already_issued_for_the_next_due_date_gets_the_new_price(): void
    {
        app(GenerateInvoices::class)->handle(CarbonImmutable::parse('2026-11-20'));
        $this->service->update(['new_price_cents' => 7000, 'new_price_from' => '2027-12-01']);

        app(ChangeProduct::class)->handle($this->service->fresh(), $this->pro, 12000);

        $notice = Invoice::query()->sole();
        $this->assertSame([12000, 12000], [$notice->items()->sole()->amount_cents, $notice->total_cents]);
        $this->assertNull($this->service->fresh()->new_price_from, 'a price change scheduled before is replaced');
    }

    public function test_charging_the_difference_now_bills_the_days_left_without_moving_the_due_date(): void
    {
        // 39 of the cycle's 365 days left at 60.00 more a year.
        $this->assertSame(641, ChangeProduct::difference($this->service, 12000));

        $result = app(ChangeProduct::class)->handle($this->service, $this->pro, 12000, chargeNow: true);

        $notice = $result['invoice'];
        $line = $notice->items()->sole();
        $this->assertSame([641, InvoiceItem::UPGRADE, '2026-10-23', '2026-12-01'], [$notice->total_cents, $line->fee, $line->due_date->toDateString(), $line->period_end->toDateString()]);
        $this->assertSame('2026-10-23', $notice->due_date->toDateString());
        $this->assertNotNull($notice->fresh()->emailed_at, 'emailed to the client');

        app(RecordInvoicePayment::class)->handle($notice, CarbonImmutable::today(), 'bank');

        $this->assertSame(InvoiceStatus::Paid, $notice->fresh()->status);
        $this->assertSame('2026-12-01', $this->service->fresh()->next_due_date->toDateString());
        $this->assertSame(641, $this->service->payments()->sole()->amount_cents);

        // The next cycle is billed as usual, at the new price.
        app(GenerateInvoices::class)->handle(CarbonImmutable::parse('2026-11-20'));
        $this->assertSame(12000, Invoice::query()->whereKeyNot($notice->id)->sole()->total_cents);
    }

    public function test_credit_pays_the_difference(): void
    {
        $notice = app(ChangeProduct::class)->handle($this->service, $this->pro, 12000, chargeNow: true)['invoice'];
        app(ClientCredit::class)->record($this->service->client, CreditType::Deposit, 1000, 'Test');

        app(ApplyCredit::class)->forClient($this->service->client->fresh());

        $this->assertSame(InvoiceStatus::Paid, $notice->fresh()->status);
        $this->assertSame(359, $this->service->client->fresh()->credit_cents);
        $this->assertSame('2026-12-01', $this->service->fresh()->next_due_date->toDateString());
    }

    public function test_a_downgrade_with_the_difference_now_goes_into_the_clients_credit(): void
    {
        config(['gaiacp.credit.enabled' => true]);
        $this->service->update(['product_id' => $this->pro->id, 'package' => 'pro', 'price_cents' => 12000, 'label' => 'Hosting Pro']);

        $result = app(ChangeProduct::class)->handle($this->service->fresh(), $this->basic, 6000, chargeNow: true);

        $this->assertNull($result['invoice']);
        $this->assertSame(641, $result['credited']);
        $this->assertSame(641, $this->service->client->fresh()->credit_cents);
        $this->assertSame('basic', $this->service->fresh()->package);
    }

    public function test_only_products_of_the_same_kind(): void
    {
        Product::factory()->maintenance()->create();
        Product::factory()->create(['name' => 'Old plan', 'retired' => true]);

        $this->assertSame(['Hosting Pro'], ChangeProduct::products($this->service)->pluck('name')->all());

        $this->expectException(\InvalidArgumentException::class);
        app(ChangeProduct::class)->handle($this->service, $this->basic, 6000);
    }
}
