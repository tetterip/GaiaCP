<?php

namespace Tests\Feature;

use App\Actions\PlaceOrder;
use App\Actions\RecordPayment;
use App\Enums\AutoSetup;
use App\Enums\BillingCycle;
use App\Enums\ServiceStatus;
use App\Enums\ServiceType;
use App\Invoices\GenerateInvoices;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Service;
use App\Reports\IncomeForecast;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** Billing cycle One time: other services billed once and Completed once paid and set up. */
class OneTimeServicesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->travelTo(CarbonImmutable::parse('2026-10-25 09:00'));
    }

    private function oneTime(array $attributes = []): Service
    {
        return Service::factory()->create($attributes + [
            'type' => ServiceType::Other,
            'server_id' => null,
            'label' => 'Moving the site',
            'domain' => null,
            'username' => null,
            'package' => null,
            'price_cents' => 5000,
            'billing_cycle' => BillingCycle::OneTime,
            'start_date' => today(),
            'next_due_date' => today(),
        ]);
    }

    public function test_the_cycle_never_moves_the_date_and_is_only_for_other_services(): void
    {
        $this->assertSame('2026-10-31', BillingCycle::OneTime->advance(CarbonImmutable::parse('2026-10-31'), 31)->toDateString());
        $this->assertSame(0, BillingCycle::OneTime->months());
        $this->assertNotContains(BillingCycle::OneTime, BillingCycle::recurring());
        $this->assertArrayHasKey('onetime', BillingCycle::optionsFor(ServiceType::Other));
        $this->assertArrayNotHasKey('onetime', BillingCycle::optionsFor(ServiceType::Hosting));
        $this->assertArrayNotHasKey('onetime', BillingCycle::optionsFor(null));

        // A product offers it only when it is an other service.
        $prices = ['onetime' => ['enabled' => true, 'price_cents' => 5000], 'annually' => ['enabled' => true, 'price_cents' => 9000]];
        $other = Product::factory()->create(['type' => ServiceType::Other, 'prices' => $prices]);
        $hosting = Product::factory()->create(['type' => ServiceType::Hosting, 'prices' => $prices]);
        $this->assertContains(BillingCycle::OneTime, $other->cycles());
        $this->assertNotContains(BillingCycle::OneTime, $hosting->cycles());
    }

    public function test_paying_it_completes_it_and_it_is_never_billed_again(): void
    {
        $client = Client::factory()->create();
        $order = app(PlaceOrder::class)->handle($client, [[
            'type' => ServiceType::Other->value,
            'label' => 'Moving the site',
            'billing_cycle' => BillingCycle::OneTime->value,
            'price_cents' => 5000,
        ]]);
        $service = $order->services()->sole();
        $service->update(['auto_setup' => AutoSetup::OnPayment]);
        $this->assertSame([5000], Invoice::query()->sole()->items->pluck('amount_cents')->all());

        app(RecordPayment::class)->handle($service, 5000, today(), 'bank_transfer');

        $service->refresh();
        $this->assertSame(ServiceStatus::Completed, $service->status);
        $this->assertSame('2026-10-25', $service->next_due_date->toDateString());
        $this->assertNotNull(Invoice::query()->sole()->paid_on);
        $this->assertTrue(ActivityLog::query()->where('event', 'service.completed')->where('service_id', $service->id)->exists());
        $this->assertFalse($service->isOverdue(today()->addYear()));

        $this->travelTo(CarbonImmutable::parse('2027-10-20 09:00'));
        app(GenerateInvoices::class)->handle(today());
        $this->assertSame(1, Invoice::query()->count());
    }

    public function test_an_active_one_not_paid_yet_stays_active_and_is_owed_once(): void
    {
        $service = $this->oneTime();

        $service->completeIfPaidOnce();
        $this->assertSame(ServiceStatus::Active, $service->refresh()->status);

        // The forecast counts it once instead of every cycle.
        $dues = (new IncomeForecast(today()))->dues()->filter(fn (array $due) => $due['service']->is($service));
        $this->assertCount(1, $dues);
    }

    public function test_a_recurring_service_is_never_completed(): void
    {
        $service = Service::factory()->create();
        app(RecordPayment::class)->handle($service, 6000, today(), 'bank_transfer');

        $this->assertSame(ServiceStatus::Active, $service->refresh()->status);
    }
}
