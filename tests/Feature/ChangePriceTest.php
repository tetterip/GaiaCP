<?php

namespace Tests\Feature;

use App\Actions\ChangePrice;
use App\Enums\BillingCycle;
use App\Enums\DomainRegistrationType;
use App\Enums\MailTemplate;
use App\Enums\ServiceStatus;
use App\Enums\UserRole;
use App\Filament\Client\Resources\Services\Pages\ViewService;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Filament\Resources\Services\Pages\EditService;
use App\Filament\Resources\Services\Pages\ListServices;
use App\Filament\Resources\Tlds\Pages\ManageTlds;
use App\Invoices\GenerateInvoices;
use App\Mail\ClientMail;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\Product;
use App\Models\Service;
use App\Models\Tld;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\FakesWhm;
use Tests\TestCase;

/** Change price with notice (App\Actions\ChangePrice): scheduled on services, billed from the date, clients emailed. */
class ChangePriceTest extends TestCase
{
    use FakesWhm, RefreshDatabase;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->fakeWhm();
        $this->travelTo(CarbonImmutable::parse('2026-10-23'));
        config(['gaiacp.invoices.days_before' => 14, 'gaiacp.tax.enabled' => false, 'gaiacp.automation.price_change_notice_days' => 30]);
        $this->client = Client::factory()->create(['first_name' => 'Maria', 'last_name' => 'Papa']);
        $this->actingAs(User::factory()->create());
    }

    private function hosting(string $due, int $price = 6000, array $attributes = []): Service
    {
        return Service::factory()->for($this->client)->create($attributes + [
            'label' => 'Starter', 'domain' => 'maria.gr', 'price_cents' => $price, 'billing_cycle' => BillingCycle::Annually,
            'start_date' => CarbonImmutable::parse($due)->subYear(), 'next_due_date' => $due,
        ]);
    }

    public function test_a_raise_is_billed_from_its_date_and_then_becomes_the_price(): void
    {
        $before = $this->hosting('2026-11-01');
        $after = $this->hosting('2026-12-15', attributes: ['domain' => 'shop.gr']);

        $summary = app(ChangePrice::class)->schedule([$before, $after], 'percent', '10', CarbonImmutable::parse('2026-12-01'));

        $this->assertSame(['scheduled' => 2, 'skipped' => 0, 'emailed' => 1], $summary);
        $this->assertSame(6600, $after->fresh()->new_price_cents);
        Mail::assertSent(ClientMail::class, fn (ClientMail $mail) => $mail->mailTemplate === MailTemplate::PriceChange
            && $mail->values['effective_date'] === '01/12/2026'
            && str_contains((string) $mail->values['price_changes'], '€66.00')
            && str_contains($mail->render(), 'shop.gr'));

        // Due before the date: the old price, and the change waits for the next cycle
        app(GenerateInvoices::class)->handle(CarbonImmutable::parse('2026-10-23'));
        $this->assertSame(6000, $before->invoiceItems()->sole()->amount_cents);
        $this->assertSame(6600, $before->fresh()->new_price_cents, 'still scheduled');

        // Due after it: the new price, which becomes the service's own
        app(GenerateInvoices::class)->handle(CarbonImmutable::parse('2026-12-05'));
        $this->assertSame(6600, $after->invoiceItems()->sole()->amount_cents);
        $this->assertSame(6600, $after->fresh()->price_cents);
        $this->assertNull($after->fresh()->new_price_from);
        $this->assertSame(1, ActivityLog::query()->where('event', 'service.price_changed')->count());
    }

    public function test_catalog_prices_come_from_the_product_or_the_tld(): void
    {
        $product = Product::factory()->priced(9000)->create();
        $hosting = $this->hosting('2027-03-01', attributes: ['product_id' => $product->id]);
        Tld::query()->create(['tld' => '.gr', 'renew_2y_cents' => 3000]);
        $domain = Service::factory()->domain()->for($this->client)->create(['domain' => 'maria.gr', 'price_cents' => 2400, 'billing_cycle' => BillingCycle::Biennially]);
        $tracked = Service::factory()->domain()->for($this->client)->create(['domain' => 'other.gr', 'price_cents' => 2400, 'billing_cycle' => BillingCycle::Biennially]);
        $tracked->registration()->update(['registration_type' => DomainRegistrationType::External]);

        $this->assertSame(9000, ChangePrice::newPrice($hosting, 'catalog'));
        $this->assertSame(3000, ChangePrice::newPrice($domain->fresh(), 'catalog'));
        $this->assertNull(ChangePrice::newPrice($tracked->fresh(), 'catalog'), 'not at our registrar');
        $this->assertSame(5050, ChangePrice::newPrice($this->hosting('2027-01-01', 4999), 'percent', '1.02'));
    }

    public function test_ending_services_and_unchanged_prices_are_left_alone_and_the_email_is_optional(): void
    {
        $ending = $this->hosting('2027-01-01', attributes: ['auto_terminate' => true]);
        $same = $this->hosting('2027-01-01', 5000);
        $pending = $this->hosting('2027-01-01', attributes: ['status' => ServiceStatus::Pending]);

        $summary = app(ChangePrice::class)->schedule([$ending, $same, $pending], 'amount', '50', CarbonImmutable::parse('2026-12-01'), email: false);

        $this->assertSame(['scheduled' => 0, 'skipped' => 3, 'emailed' => 0], $summary);
        Mail::assertNothingSent();
    }

    public function test_the_admin_schedules_and_cancels_on_the_service_page(): void
    {
        $service = $this->hosting('2027-01-10');

        Livewire::test(EditService::class, ['record' => $service->id])
            ->callAction('changePrice', ['mode' => 'amount', 'value' => '72,50', 'from' => '2026-11-01'])
            ->assertHasActionErrors(['from']); // sooner than 30 days

        Livewire::test(EditService::class, ['record' => $service->id])
            ->callAction('changePrice', ['mode' => 'amount', 'value' => '72,50', 'from' => '2026-12-01', 'email' => true])
            ->assertHasNoActionErrors()
            ->assertNotified(__(':count service(s) get the new price from :date', ['count' => 1, 'date' => '01/12/2026']));

        $this->assertSame(7250, $service->fresh()->new_price_cents);

        Livewire::test(EditService::class, ['record' => $service->id])
            ->callAction('cancelPriceChange')
            ->assertNotified(__('Price change cancelled'));
        $this->assertNull($service->fresh()->new_price_cents);
    }

    public function test_bulk_product_and_tld_actions(): void
    {
        $a = $this->hosting('2027-01-10');
        $b = $this->hosting('2027-02-10', 8000);

        Livewire::test(ListServices::class)
            ->selectTableRecords([$a->id, $b->id])
            ->callAction(TestAction::make('changePrice')->table()->bulk(), ['mode' => 'percent', 'value' => '5', 'from' => '2026-12-01', 'email' => false])
            ->assertHasNoActionErrors();
        $this->assertSame([6300, 8400], [$a->fresh()->new_price_cents, $b->fresh()->new_price_cents]);

        $product = Product::factory()->priced(7000)->create();
        $a->update(['product_id' => $product->id]);
        Livewire::test(ListProducts::class)
            ->callAction(TestAction::make('changeServicesPrice')->table($product), ['from' => '2026-12-01', 'email' => false]);
        $this->assertSame(7000, $a->fresh()->new_price_cents);

        $tld = Tld::query()->create(['tld' => '.gr', 'renew_1y_cents' => 1800]);
        $domain = Service::factory()->domain()->for($this->client)->create(['domain' => 'maria.gr', 'price_cents' => 1500, 'billing_cycle' => BillingCycle::Annually]);
        Service::factory()->domain()->for($this->client)->create(['domain' => 'maria.com.gr', 'price_cents' => 1500]);
        Tld::query()->create(['tld' => '.com.gr', 'renew_1y_cents' => 2500]);
        Livewire::test(ManageTlds::class)
            ->callAction(TestAction::make('changeDomainsPrice')->table($tld), ['from' => '2026-12-01', 'email' => false]);
        $this->assertSame(1800, $domain->fresh()->new_price_cents);
        $this->assertSame(1, Service::query()->whereNotNull('new_price_cents')->where('type', 'domain')->count(), '.com.gr is another TLD');
    }

    public function test_the_client_sees_the_coming_price(): void
    {
        $service = $this->hosting('2027-01-10');
        app(ChangePrice::class)->schedule([$service], 'amount', '66', CarbonImmutable::parse('2026-12-01'), email: false);

        Filament::setCurrentPanel('client');
        $this->actingAs(User::factory()->create(['role' => UserRole::Client, 'client_id' => $this->client->id, 'email' => $this->client->email]), 'client');

        Livewire::test(ViewService::class, ['record' => $service->getRouteKey()])
            ->assertSee(__('From :date: :price', ['date' => '01/12/2026', 'price' => '€66.00']));
    }
}
