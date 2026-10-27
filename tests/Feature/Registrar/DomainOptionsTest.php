<?php

namespace Tests\Feature\Registrar;

use App\Actions\PlaceOrder;
use App\Actions\RecordInvoicePayment;
use App\Actions\RecordPayment;
use App\Enums\BillingCycle;
use App\Enums\InvoiceStatus;
use App\Enums\ServiceStatus;
use App\Enums\ServiceType;
use App\Filament\Clusters\Settings\Pages\DomainSettings;
use App\Filament\Resources\Services\Pages\EditService;
use App\Invoices\AddDomainLateFees;
use App\Invoices\GenerateInvoices;
use App\Invoices\RenewFreeDomains;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Service;
use App\Models\User;
use App\Rules\ValidDomain;
use App\Support\DomainName;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Livewire\Livewire;
use Plugins\EasyGr\EasyRegistrar;
use Tests\FakesEasy;
use Tests\TestCase;

class DomainOptionsTest extends TestCase
{
    use FakesEasy, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeEasy();
        Mail::fake();
        config(['gaiacp.invoices.days_before' => 14, 'gaiacp.tax.enabled' => false]);
    }

    private function domain(string $name, array $attributes = []): Service
    {
        return Service::factory()->domain()->create(['domain' => $name, 'start_date' => '2025-11-01', 'next_due_date' => '2026-11-01', ...$attributes]);
    }

    public function test_settings_page_saves_the_domain_options(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(DomainSettings::class)
            ->fillForm([
                'domains' => ['nameservers' => ['ns1.example.com', 'ns2.example.com'], 'auto_renew' => false, 'renew_on_payment' => false,
                    'free_renew_requires_product' => false, 'idn' => true, 'late_fees' => true],
            ])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertFalse(config('gaiacp.domains.auto_renew'));
        $this->assertFalse(config('gaiacp.domains.renew_on_payment'));
        $this->assertFalse(config('gaiacp.domains.free_renew_requires_product'));
        $this->assertTrue(config('gaiacp.domains.idn'));
        $this->assertTrue(config('gaiacp.domains.late_fees'));
    }

    public function test_new_domains_take_the_default_auto_renew(): void
    {
        config(['gaiacp.domains.auto_renew' => false]);
        $client = Client::factory()->create();

        $order = app(PlaceOrder::class)->handle($client, [
            ['type' => 'domain', 'domain' => 'acme.gr', 'price_cents' => 1500, 'billing_cycle' => BillingCycle::Annually],
            ['type' => 'domain', 'domain' => 'kept.gr', 'price_cents' => 1500, 'billing_cycle' => BillingCycle::Annually, 'auto_renew' => true],
            ['type' => 'maintenance', 'label' => 'Maintenance', 'price_cents' => 1000, 'billing_cycle' => BillingCycle::Annually],
        ]);

        $services = $order->services()->get()->keyBy('label');
        $this->assertFalse($services['acme.gr']->auto_renew);
        $this->assertTrue($services['kept.gr']->auto_renew);
        $this->assertFalse($services['Maintenance']->auto_terminate);
    }

    public function test_auto_renew_is_auto_terminate_the_other_way_round(): void
    {
        $service = $this->domain('acme.gr');
        $service->update(['auto_renew' => false]);

        $this->assertTrue($service->fresh()->auto_terminate);
        $this->assertFalse($service->fresh()->auto_renew);
    }

    public function test_the_service_form_switches_auto_renew_off_and_validates_the_domain(): void
    {
        $this->actingAs(User::factory()->create());
        $service = $this->domain('acme.gr');

        Livewire::test(EditService::class, ['record' => $service->id])
            ->assertSchemaStateSet(['auto_renew' => true])
            ->fillForm(['auto_renew' => false, 'auto_terminate_reason' => 'Client asked'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue($service->fresh()->auto_terminate);
        $this->assertSame('Client asked', $service->fresh()->auto_terminate_reason);

        Livewire::test(EditService::class, ['record' => $service->id])
            ->fillForm(['domain' => 'https://acme.gr/'])
            ->call('save')
            ->assertHasFormErrors(['domain']);
    }

    public function test_a_domain_that_does_not_auto_renew_gets_no_payment_notice(): void
    {
        $this->domain('acme.gr', ['auto_terminate' => true]);

        $this->assertSame([], app(GenerateInvoices::class)->handle(CarbonImmutable::parse('2026-10-25')));
    }

    public function test_payment_does_not_renew_when_renew_on_payment_is_off(): void
    {
        config(['gaiacp.domains.renew_on_payment' => false]);
        $service = $this->domain('acme.gr');
        $this->easyDomains['acme.gr'] = ['expires' => '2026-11-01'];

        app(RecordPayment::class)->handle($service, 1500, CarbonImmutable::parse('2026-10-20'), 'bank_transfer');

        $this->assertSame([], $this->easyCalls);
        $this->assertSame('2027-11-01', $service->fresh()->next_due_date->toDateString());
    }

    public function test_free_domain_with_hosting_is_renewed_by_the_daily_run(): void
    {
        $service = $this->domain('acme.gr', ['price_cents' => 0]);
        Service::factory()->for($service->client)->create(['domain' => 'acme.gr']);
        $this->easyDomains['acme.gr'] = ['expires' => '2026-11-01'];

        $result = app(RenewFreeDomains::class)->handle(CarbonImmutable::parse('2026-10-20'));
        $again = app(RenewFreeDomains::class)->handle(CarbonImmutable::parse('2026-10-20'));

        $this->assertSame(['renewed' => 1, 'ended' => 0], $result);
        $this->assertSame(['renewed' => 0, 'ended' => 0], $again);
        $this->assertSame('2027-11-01', $service->fresh()->next_due_date->toDateString());
        $this->assertSame(0, $service->payments()->sole()->amount_cents);
        $this->assertSame([EasyRegistrar::RENEW], $this->easyActions());
    }

    public function test_free_domain_without_hosting_is_set_to_end(): void
    {
        $service = $this->domain('acme.gr', ['price_cents' => 0]);
        Service::factory()->for($service->client)->create(['domain' => 'acme.gr', 'status' => ServiceStatus::Terminated]);

        $result = app(RenewFreeDomains::class)->handle(CarbonImmutable::parse('2026-10-20'));

        $this->assertSame(['renewed' => 0, 'ended' => 1], $result);
        $this->assertTrue($service->fresh()->auto_terminate);
        $this->assertSame(0, $service->payments()->count());
        $this->assertTrue(ActivityLog::where('event', 'domain.not_renewed')->exists());
    }

    public function test_free_domain_renews_without_hosting_when_not_required(): void
    {
        config(['gaiacp.domains.free_renew_requires_product' => false]);
        $service = $this->domain('acme.gr', ['price_cents' => 0]);
        $this->easyDomains['acme.gr'] = ['expires' => '2026-11-01'];

        $this->assertSame(['renewed' => 1, 'ended' => 0], app(RenewFreeDomains::class)->handle(CarbonImmutable::parse('2026-10-20')));
    }

    public function test_free_domain_counts_hosting_from_its_order(): void
    {
        $service = $this->domain('acme.gr', ['price_cents' => 0]);
        $order = Order::create(['client_id' => $service->client_id, 'status' => 'completed']);
        $service->update(['order_id' => $order->id]);
        Service::factory()->for($service->client)->create(['domain' => 'other.gr', 'order_id' => $order->id]);
        $this->easyDomains['acme.gr'] = ['expires' => '2026-11-01'];

        $this->assertSame(['renewed' => 1, 'ended' => 0], app(RenewFreeDomains::class)->handle(CarbonImmutable::parse('2026-10-20')));
    }

    public function test_idn_domains_are_refused_unless_allowed(): void
    {
        $validate = fn (string $domain) => Validator::make(['domain' => $domain], ['domain' => [new ValidDomain]])->passes();

        $this->assertTrue($validate('acme.gr'));
        $this->assertFalse($validate('http://acme.gr'));
        $this->assertFalse($validate('acme'));
        $this->assertFalse($validate('παράδειγμα.gr'));

        config(['gaiacp.domains.idn' => true]);

        $this->assertTrue($validate('Παράδειγμα.gr'));
        $this->assertSame('παράδειγμα.gr', DomainName::normalize('XN--HXAJBHEG2AZ3AL.GR.'));
        $this->assertSame('xn--hxajbheg2az3al.gr', DomainName::toAscii('παράδειγμα.gr'));
    }

    public function test_an_idn_is_stored_as_typed_and_sent_to_the_registrar_in_punycode(): void
    {
        config(['gaiacp.domains.idn' => true]);
        $service = $this->domain('Παράδειγμα.gr');
        $this->easyDomains['xn--hxajbheg2az3al.gr'] = ['expires' => '2026-11-01'];

        app(RecordPayment::class)->handle($service, 1500, CarbonImmutable::parse('2026-10-20'), 'bank_transfer');

        $this->assertSame('παράδειγμα.gr', $service->fresh()->domain);
        $this->assertSame([[EasyRegistrar::RENEW, ['domain' => 'xn--hxajbheg2az3al.gr', 'years' => '1']]], $this->easyCalls);
    }

    public function test_late_fees_are_added_to_an_expired_domains_notice_and_paid_with_it(): void
    {
        config(['gaiacp.domains.late_fees' => true]);
        $this->easyRegistrar->tlds()->where('tld', '.gr')->update(['grace_fee_cents' => 500, 'grace_days' => 30, 'redemption_fee_cents' => 4000]);
        $service = $this->domain('acme.gr');
        $service->registration()->update(['expires_at' => '2026-11-01']);
        $this->easyDomains['acme.gr'] = ['expires' => '2026-11-01'];

        app(GenerateInvoices::class)->handle(CarbonImmutable::parse('2026-10-20'));
        $fees = app(AddDomainLateFees::class);

        $this->assertSame(0, $fees->handle(CarbonImmutable::parse('2026-11-01')), 'not expired yet');
        $this->assertSame(1, $fees->handle(CarbonImmutable::parse('2026-11-05')));
        $this->assertSame(0, $fees->handle(CarbonImmutable::parse('2026-11-06')), 're-run adds nothing');
        $this->assertSame(1, $fees->handle(CarbonImmutable::parse('2026-12-02')), 'redemption after the grace days');

        $invoice = Invoice::sole();
        $this->assertSame([null, 'grace', 'redemption'], $invoice->items()->orderBy('id')->pluck('fee')->all());
        $this->assertSame(1500 + 500 + 4000, $invoice->total_cents);

        app(RecordInvoicePayment::class)->handle($invoice, CarbonImmutable::parse('2026-12-03'), 'bank_transfer');

        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()->status);
        $this->assertSame(6000, $service->payments()->sole()->amount_cents);
        $this->assertSame('2027-11-01', $service->fresh()->next_due_date->toDateString());
        $this->assertSame([EasyRegistrar::RENEW], $this->easyActions());
    }

    public function test_no_late_fees_when_switched_off_or_for_tracked_domains(): void
    {
        $this->easyRegistrar->tlds()->update(['grace_fee_cents' => 500, 'grace_days' => 30]);
        $service = $this->domain('acme.gr');
        $service->registration()->update(['expires_at' => '2026-11-01']);
        $tracked = $this->domain('tracked.gr');
        $tracked->registration()->update(['expires_at' => '2026-11-01', 'registration_type' => 'external']);
        app(GenerateInvoices::class)->handle(CarbonImmutable::parse('2026-10-20'));

        $this->assertSame(0, app(AddDomainLateFees::class)->handle(CarbonImmutable::parse('2026-11-05')));

        config(['gaiacp.domains.late_fees' => true]);

        $this->assertSame(1, app(AddDomainLateFees::class)->handle(CarbonImmutable::parse('2026-11-05')));
        $this->assertSame(ServiceType::Domain, Invoice::query()->whereHas('items', fn ($q) => $q->whereNotNull('fee'))->sole()->items()->first()->service->type);
    }

    public function test_paying_the_service_directly_also_settles_its_fee_line(): void
    {
        config(['gaiacp.domains.late_fees' => true]);
        $this->easyRegistrar->tlds()->update(['grace_fee_cents' => 500]);
        $service = $this->domain('acme.gr');
        $service->registration()->update(['expires_at' => '2026-11-01']);
        $this->easyDomains['acme.gr'] = ['expires' => '2026-11-01'];
        app(GenerateInvoices::class)->handle(CarbonImmutable::parse('2026-10-20'));
        app(AddDomainLateFees::class)->handle(CarbonImmutable::parse('2026-11-05'));

        app(RecordPayment::class)->handle($service, 2000, CarbonImmutable::parse('2026-11-05'), 'bank_transfer');

        $this->assertSame(InvoiceStatus::Paid, Invoice::sole()->status);
    }
}
