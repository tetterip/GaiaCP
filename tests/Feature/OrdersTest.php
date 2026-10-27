<?php

namespace Tests\Feature;

use App\Actions\CompleteOrder;
use App\Actions\PlaceOrder;
use App\Actions\RecordInvoicePayment;
use App\Actions\RecordOrderPayment;
use App\Enums\BillingCycle;
use App\Enums\DomainRegistrationType;
use App\Enums\OrderStatus;
use App\Enums\ServiceStatus;
use App\Enums\ServiceType;
use App\Filament\Resources\Orders\Pages\CreateOrder;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Filament\Resources\Orders\RelationManagers\ItemsRelationManager;
use App\Filament\Resources\Services\Pages\EditService;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\Order;
use App\Models\Product;
use App\Models\Registrar;
use App\Models\Server;
use App\Models\User;
use App\Provisioning\ProvisioningException;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\Repeater;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Plugins\EasyGr\EasyRegistrar;
use Tests\FakesEasy;
use Tests\FakesWhm;
use Tests\TestCase;

class OrdersTest extends TestCase
{
    use FakesEasy, FakesWhm, RefreshDatabase;

    private Client $client;

    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-09 10:00');
        $this->fakeWhm();
        $this->fakeEasy();
        config(['gaiacp.domains.nameservers' => ['ns1.host.gr', 'ns2.host.gr']]);

        $this->client = Client::factory()->create();
        $this->server = Server::factory()->create();
    }

    /** The usual order: a domain, hosting for it and yearly maintenance. */
    private function place(array $domain = []): Order
    {
        return app(PlaceOrder::class)->handle($this->client, [
            ['type' => 'domain', 'label' => 'Domain registration', 'domain' => 'Acme.gr', 'price_cents' => 1800, 'first_payment_cents' => 2000,
                'billing_cycle' => BillingCycle::Annually, 'registration_type' => DomainRegistrationType::Register, ...$domain],
            ['type' => 'hosting', 'label' => 'Hosting Basic', 'domain' => '', 'server_id' => $this->server->id, 'package' => 'basic',
                'price_cents' => 6000, 'billing_cycle' => BillingCycle::Annually],
            ['type' => 'maintenance', 'label' => 'Maintenance', 'price_cents' => 12000, 'billing_cycle' => BillingCycle::Annually],
        ], 'Phone order');
    }

    public function test_placing_creates_pending_services_and_calls_nothing(): void
    {
        $order = $this->place();

        $this->assertSame(OrderStatus::Pending, $order->status);
        $this->assertCount(3, $order->services);
        $this->assertTrue($order->services->every(fn ($s) => $s->status === ServiceStatus::Pending && $s->client_id === $this->client->id));
        $this->assertSame('acme.gr', $order->services->firstWhere('type', ServiceType::Hosting)->domain); // the order's domain
        $this->assertSame('acme.gr', $order->services->firstWhere('type', ServiceType::Domain)->label);
        $this->assertSame(DomainRegistrationType::Register, $order->services->firstWhere('type', ServiceType::Domain)->domainDetails->registration_type);
        $this->assertSame(2000 + 6000 + 12000, $order->totalCents());
        $this->assertSame([], $this->easyCalls);
        $this->assertSame([], $this->whmCalls);
    }

    public function test_completing_registers_the_domain_creates_hosting_and_activates_the_rest(): void
    {
        $order = $this->place();

        $failures = app(CompleteOrder::class)->handle($order);

        $this->assertSame([], $failures);
        $this->assertContains(EasyRegistrar::REGISTER, $this->easyActions());
        $this->assertArrayHasKey('acme', $this->whmAccounts);
        $this->assertTrue($order->services()->get()->every(fn ($s) => $s->status === ServiceStatus::Active));
        $this->assertSame(OrderStatus::Completed, $order->refresh()->status);
        $this->assertNotNull($order->completed_at);
    }

    public function test_a_failing_item_stays_pending_and_a_retry_finishes_it(): void
    {
        $order = $this->place();
        $this->easyFailures[EasyRegistrar::REGISTER] = ['2302' => 'Object exists'];

        $failures = app(CompleteOrder::class)->handle($order);

        $domain = $order->services()->where('type', ServiceType::Domain)->first();
        $this->assertSame([$domain->id], array_keys($failures));
        $this->assertSame(ServiceStatus::Pending, $domain->status);
        $this->assertSame(OrderStatus::Pending, $order->refresh()->status);
        $this->assertSame(2, $order->services()->where('status', ServiceStatus::Active)->count());

        unset($this->easyFailures[EasyRegistrar::REGISTER]);
        $whmCreates = count(array_filter($this->whmCalls, fn ($c) => $c[0] === 'createacct'));

        $this->assertSame([], app(CompleteOrder::class)->handle($order));
        $this->assertSame(OrderStatus::Completed, $order->refresh()->status);
        $this->assertSame($whmCreates, count(array_filter($this->whmCalls, fn ($c) => $c[0] === 'createacct'))); // hosting not created twice
    }

    public function test_order_payment_records_first_payments_without_renewing(): void
    {
        $order = $this->place();
        app(CompleteOrder::class)->handle($order);
        $this->easyCalls = [];

        $payments = app(RecordOrderPayment::class)->handle($order, CarbonImmutable::parse('2026-10-09'), 'bank_transfer');

        $this->assertSame([2000, 6000, 12000], collect($payments)->pluck('amount_cents')->sort()->values()->all());
        $this->assertSame([], $this->easyCalls); // the registration already covers the first year
        $this->assertTrue($order->services()->get()->every(fn ($s) => $s->next_due_date->toDateString() === '2027-10-09'));
        $this->assertSame([], app(RecordOrderPayment::class)->handle($order, CarbonImmutable::parse('2026-10-09'), 'bank_transfer'));
    }

    public function test_admin_places_and_completes_an_order(): void
    {
        $this->actingAs(User::factory()->create());
        $this->easyRegistrar->tlds()->where('tld', '.com.gr')->update(['register_2y_cents' => 3000]);
        $undo = Repeater::fake();

        Livewire::test(CreateOrder::class)
            ->fillForm([
                'client_id' => $this->client->id,
                'items' => [
                    ['type' => 'domain', 'domain' => 'shop.com.gr', 'registration_type' => 'register', 'years' => 2],
                    ['type' => 'hosting', 'label' => 'Hosting', 'server_id' => $this->server->id, 'package' => 'basic', 'price_cents' => '60', 'billing_cycle' => 'annually'],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $undo();

        $order = Order::sole();
        $domain = $order->services()->where('type', ServiceType::Domain)->sole();
        $this->assertSame(BillingCycle::Biennially, $domain->billing_cycle);
        $this->assertSame('shop.com.gr', $domain->label); // a domain is called by its name
        $this->assertSame('shop.com.gr', $order->services()->where('type', ServiceType::Hosting)->value('domain'));

        Livewire::test(ViewOrder::class, ['record' => $order->id])
            ->callAction('completeOrder')
            ->assertNotified('Order completed');

        $this->assertSame('2', collect($this->easyCalls)->firstWhere(0, EasyRegistrar::REGISTER)[1]['years']);
        $this->assertSame(OrderStatus::Completed, $order->refresh()->status);
    }

    public function test_complete_order_failure_ends_each_error_before_the_hint(): void
    {
        $this->actingAs(User::factory()->create());
        $order = $this->place();
        $this->easyFailures[EasyRegistrar::REGISTER] = ['2306' => 'Συμπλήρωσε την περιοχή'];

        Livewire::test(ViewOrder::class, ['record' => $order->id])
            ->callAction('completeOrder')
            ->assertNotified(Notification::make()
                ->title('Some items are still pending')
                ->body('acme.gr: easy.gr: 2306 - Συμπλήρωσε την περιοχή. Fix them and run "Complete order" again; finished items are skipped.')
                ->danger()
                ->persistent());
    }

    public function test_complete_order_lets_the_admin_pick_each_items_registrar_and_server(): void
    {
        $this->actingAs(User::factory()->create());
        $order = $this->place();
        $domain = $order->services()->where('type', ServiceType::Domain)->sole();
        $hosting = $order->services()->where('type', ServiceType::Hosting)->sole();
        $otherRegistrar = Registrar::factory()->create(['name' => 'Second easy.gr']);
        $otherServer = Server::factory()->create(['name' => 'nl1']);

        Livewire::test(ViewOrder::class, ['record' => $order->id])
            ->mountAction('completeOrder')
            // The current choices come pre-selected.
            ->assertSchemaStateSet([
                "items.{$domain->id}.registrar_id" => null,
                "items.{$hosting->id}.server_id" => $this->server->id,
                "items.{$hosting->id}.package" => 'basic',
            ], 'mountedActionSchema0')
            ->setActionData([
                "items.{$domain->id}.registrar_id" => $otherRegistrar->id,
                "items.{$hosting->id}.server_id" => $otherServer->id,
                "items.{$hosting->id}.package" => 'pro',
            ])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified('Order completed');

        $this->assertSame($otherRegistrar->id, $domain->refresh()->domainDetails->registrar_id);
        $this->assertSame(ServiceStatus::Active, $domain->status);
        $hosting->refresh();
        $this->assertSame([$otherServer->id, 'pro', ServiceStatus::Active], [$hosting->server_id, $hosting->package, $hosting->status]);
        $this->assertSame(2, ActivityLog::query()->where('event', 'order.item_changed')->count());
    }

    public function test_assigning_ignores_finished_items_and_refuses_inactive_choices(): void
    {
        $order = $this->place();
        $domain = $order->services()->where('type', ServiceType::Domain)->sole();
        $hosting = $order->services()->where('type', ServiceType::Hosting)->sole();
        $inactive = Server::factory()->create(['active' => false]);

        try {
            app(CompleteOrder::class)->assign($order, [$hosting->id => ['server_id' => $inactive->id]]);
            $this->fail('An inactive server was accepted.');
        } catch (ProvisioningException) {
            $this->assertSame($this->server->id, $hosting->refresh()->server_id);
        }

        $hosting->update(['status' => ServiceStatus::Active]);
        $other = Server::factory()->create();
        app(CompleteOrder::class)->assign($order, [$hosting->id => ['server_id' => $other->id], $domain->id => ['registrar_id' => null]]);

        $this->assertSame($this->server->id, $hosting->refresh()->server_id); // no longer pending
        $this->assertSame(0, ActivityLog::query()->where('event', 'order.item_changed')->count()); // nothing changed
    }

    public function test_hosting_needs_its_own_domain_unless_the_order_has_exactly_one(): void
    {
        $this->actingAs(User::factory()->create());
        $undo = Repeater::fake();
        $hosting = ['type' => 'hosting', 'label' => 'Hosting', 'server_id' => $this->server->id, 'package' => 'basic', 'price_cents' => '60', 'billing_cycle' => 'annually'];

        // No domain in the order: nothing to take.
        Livewire::test(CreateOrder::class)
            ->fillForm(['client_id' => $this->client->id, 'items' => [$hosting]])
            ->call('create')
            ->assertHasFormErrors(['items.0.domain' => 'required']);

        // Two domains: which one?
        Livewire::test(CreateOrder::class)
            ->fillForm(['client_id' => $this->client->id, 'items' => [
                ['type' => 'domain', 'domain' => 'one.gr', 'registration_type' => 'register', 'years' => 2, 'price_cents' => '20'],
                ['type' => 'domain', 'domain' => 'two.gr', 'registration_type' => 'register', 'years' => 2, 'price_cents' => '20'],
                $hosting,
            ]])
            ->call('create')
            ->assertHasFormErrors(['items.2.domain' => 'required']);

        // One domain: it is the hosting's, and the hint names it.
        Livewire::test(CreateOrder::class)
            ->fillForm(['client_id' => $this->client->id, 'items' => [
                ['type' => 'domain', 'domain' => 'one.gr', 'registration_type' => 'register', 'years' => 2, 'price_cents' => '20'],
                $hosting,
            ]])
            ->assertSee('Empty = one.gr, the domain of this order.')
            ->call('create')
            ->assertHasNoFormErrors();

        $undo();
        $this->assertSame('one.gr', Order::sole()->services()->where('type', ServiceType::Hosting)->value('domain'));
    }

    public function test_placing_hosting_without_a_domain_it_can_take_is_refused(): void
    {
        $this->expectException(ValidationException::class);

        try {
            app(PlaceOrder::class)->handle($this->client, [
                ['type' => 'domain', 'domain' => 'one.gr', 'price_cents' => 1800, 'billing_cycle' => BillingCycle::Annually],
                ['type' => 'domain', 'domain' => 'two.gr', 'price_cents' => 1800, 'billing_cycle' => BillingCycle::Annually],
                ['type' => 'hosting', 'label' => 'Hosting', 'server_id' => $this->server->id, 'package' => 'basic', 'price_cents' => 6000, 'billing_cycle' => BillingCycle::Annually],
            ]);
        } finally {
            $this->assertSame(0, Order::count());
        }
    }

    public function test_the_new_order_total_shows_the_tax_the_payment_notice_will_add(): void
    {
        $this->actingAs(User::factory()->create());
        config(['gaiacp.tax.enabled' => true, 'gaiacp.tax.inclusive' => false, 'gaiacp.tax.rules' => [['name' => 'ΦΠΑ', 'rate' => 24, 'country' => null]]]);
        $exempt = Client::factory()->create(['tax_exempt' => true]);
        $undo = Repeater::fake();

        Livewire::test(CreateOrder::class)
            ->fillForm(['client_id' => $this->client->id, 'items' => [
                ['type' => 'other', 'label' => 'Setup', 'price_cents' => '100', 'billing_cycle' => 'annually'],
                ['type' => 'other', 'label' => 'Care', 'price_cents' => '10.05', 'billing_cycle' => 'annually'],
            ]])
            // Line by line, as the notice adds it up: 24.00 + 2.41.
            ->assertSee('First payment total: €110.05 + ΦΠΑ 24% €26.41 = €136.46 with tax')
            ->fillForm(['client_id' => $exempt->id])
            ->assertSee('First payment total: €110.05')
            ->assertDontSee('with tax');

        $undo();
    }

    public function test_domain_prices_come_from_domain_pricing(): void
    {
        $this->actingAs(User::factory()->create());
        $this->easyRegistrar->tlds()->where('tld', '.gr')->update(['register_1y_cents' => 2000, 'renew_1y_cents' => 1800, 'transfer_1y_cents' => 0]);
        $undo = Repeater::fake();

        $page = Livewire::test(CreateOrder::class)
            ->fillForm(['client_id' => $this->client->id, 'items' => [['type' => 'domain']]])
            ->fillForm(['items.0.domain' => 'acme.gr'])
            ->assertSchemaStateSet(['items.0.price_cents' => '18.00', 'items.0.first_payment_cents' => '20.00'])
            ->fillForm(['items.0.registration_type' => 'transfer'])
            ->assertSchemaStateSet(['items.0.first_payment_cents' => '0.00']);

        $undo();
        $this->assertNotNull($page);
    }

    public function test_the_product_list_shows_only_products_of_the_chosen_type(): void
    {
        $this->actingAs(User::factory()->create());
        $hosting = Product::factory()->create();
        $maintenance = Product::factory()->maintenance()->create();
        $domain = Product::factory()->create(['name' => 'Domain', 'type' => ServiceType::Domain, 'package' => null]);
        $undo = Repeater::fake();

        $page = Livewire::test(CreateOrder::class)
            ->fillForm(['client_id' => $this->client->id, 'items' => [['type' => 'domain']]])
            ->assertFormFieldExists('items.0.product_id', fn ($field) => array_keys($field->getOptions()) === [$domain->id])
            ->fillForm(['items.0.type' => 'hosting'])
            ->assertFormFieldExists('items.0.product_id', fn ($field) => array_keys($field->getOptions()) === [$hosting->id])
            ->fillForm(['items.0.type' => 'maintenance'])
            ->assertFormFieldExists('items.0.product_id', fn ($field) => array_keys($field->getOptions()) === [$maintenance->id]);

        $undo();
        $this->assertNotNull($page);
    }

    public function test_changing_the_type_or_product_drops_the_old_prices(): void
    {
        $this->actingAs(User::factory()->create());
        $this->easyRegistrar->tlds()->where('tld', '.gr')->update(['register_1y_cents' => 2400, 'renew_1y_cents' => 2400]);
        $domain = Product::factory()->create(['name' => 'Domain', 'type' => ServiceType::Domain, 'package' => null]);
        $maintenance = Product::factory()->maintenance()->create();
        $unpriced = Product::factory()->maintenance()->create(['name' => 'Hourly support', 'prices' => []]);
        $undo = Repeater::fake();

        $page = Livewire::test(CreateOrder::class)
            ->fillForm(['client_id' => $this->client->id, 'items' => [['type' => 'domain']]])
            ->fillForm(['items.0.product_id' => $domain->id])
            ->fillForm(['items.0.domain' => 'acme.gr'])
            ->assertSchemaStateSet(['items.0.price_cents' => '24.00', 'items.0.first_payment_cents' => '24.00'])
            // Another type: the domain product and its prices go.
            ->fillForm(['items.0.type' => 'maintenance'])
            ->assertSchemaStateSet(['items.0.product_id' => null, 'items.0.price_cents' => null, 'items.0.first_payment_cents' => null])
            ->fillForm(['items.0.product_id' => $maintenance->id])
            ->assertSchemaStateSet(['items.0.label' => 'Maintenance', 'items.0.price_cents' => '120.00', 'items.0.first_payment_cents' => null])
            // A product without a price leaves the price to fill in, not the last product's.
            ->fillForm(['items.0.product_id' => $unpriced->id])
            ->assertSchemaStateSet(['items.0.label' => 'Hourly support', 'items.0.price_cents' => null])
            ->fillForm(['items.0.product_id' => $maintenance->id])
            ->fillForm(['items.0.product_id' => null])
            ->assertSchemaStateSet(['items.0.label' => null, 'items.0.price_cents' => null])
            ->assertSee('First payment total: €0.00');

        $undo();
        $this->assertNotNull($page);
    }

    public function test_a_tld_priced_only_for_two_years_is_ordered_for_two_years(): void
    {
        $this->actingAs(User::factory()->create());
        $this->easyRegistrar->tlds()->where('tld', '.gr')->update(['register_1y_cents' => null, 'register_2y_cents' => 2400, 'renew_1y_cents' => null, 'renew_2y_cents' => 2400]);
        $undo = Repeater::fake();

        $page = Livewire::test(CreateOrder::class)
            ->fillForm(['client_id' => $this->client->id, 'items' => [['type' => 'domain']]])
            ->fillForm(['items.0.domain' => 'acme.gr'])
            ->assertSchemaStateSet(['items.0.years' => 2, 'items.0.billing_cycle' => BillingCycle::Biennially, 'items.0.price_cents' => '24.00'])
            // 1 year is not on offer; picking it anyway goes back to 2.
            ->assertFormFieldExists('items.0.years', fn ($field) => array_keys($field->getOptions()) === [2])
            ->fillForm(['items.0.years' => 1])
            ->assertSchemaStateSet(['items.0.years' => 2, 'items.0.billing_cycle' => BillingCycle::Biennially])
            ->call('create')
            ->assertHasNoFormErrors();

        $undo();
        $this->assertNotNull($page);
        $this->assertSame(BillingCycle::Biennially, Order::sole()->services()->sole()->billing_cycle);
    }

    public function test_paying_the_order_notice_leaves_no_record_payment_on_its_items(): void
    {
        $this->actingAs(User::factory()->create());
        $order = $this->place();
        $notice = $order->invoices()->firstOrFail();

        app(RecordInvoicePayment::class)->handle($notice, CarbonImmutable::parse('2026-10-09'), 'bank_transfer');

        $domain = $order->services()->where('type', ServiceType::Domain)->first();
        $this->assertSame(ServiceStatus::Pending, $domain->status); // set up on Complete order, as before

        Livewire::test(ItemsRelationManager::class, ['ownerRecord' => $order, 'pageClass' => ViewOrder::class])
            ->assertTableActionHidden('recordPayment', $domain)
            ->assertSee(__('Paid'));
        Livewire::test(EditService::class, ['record' => $domain->id])
            ->assertActionHidden('recordPayment');

        // Unpaid pending items still offer it.
        $other = $this->place();
        Livewire::test(ItemsRelationManager::class, ['ownerRecord' => $other, 'pageClass' => ViewOrder::class])
            ->assertTableActionVisible('recordPayment', $other->services()->first());
    }

    public function test_editing_an_unpaid_items_price_updates_the_order_and_its_notice(): void
    {
        $this->actingAs(User::factory()->create());
        $order = $this->place();
        $hosting = $order->services()->where('type', ServiceType::Hosting)->first();
        $notice = $order->invoices()->sole();
        $this->assertSame(2000 + 6000 + 12000, $notice->total_cents);

        Livewire::test(EditService::class, ['record' => $hosting->id])
            ->fillForm(['price_cents' => '45.00'])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified(__('The unpaid payment notice was updated to the new amount.'));

        $this->assertSame(4500, $notice->items()->where('service_id', $hosting->id)->value('amount_cents'));
        $this->assertSame(2000 + 4500 + 12000, $notice->refresh()->total_cents);
        $order = $order->fresh();
        $this->assertSame(2000 + 4500 + 12000, $order->totalCents());
        $this->assertSame(2000 + 4500 + 12000, $order->totals()['total']);
        $this->assertTrue(ActivityLog::where('event', 'invoice.line_repriced')->where('service_id', $hosting->id)->exists());
    }

    public function test_a_first_payment_left_as_it_was_moves_with_the_price(): void
    {
        $this->actingAs(User::factory()->create());
        $order = $this->place();
        $domain = $order->services()->where('type', ServiceType::Domain)->first();

        // Registration 20.00 over a renewal of 18.00: a renewal of 15.00 keeps the 2.00 difference.
        Livewire::test(EditService::class, ['record' => $domain->id])
            ->assertSchemaStateSet(['first_payment_cents' => '20.00'])
            ->fillForm(['price_cents' => '15.00'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(1700, $domain->refresh()->first_payment_cents);
        $this->assertSame(1700, $order->invoices()->sole()->items()->where('service_id', $domain->id)->value('amount_cents'));

        // One set by hand is kept.
        Livewire::test(EditService::class, ['record' => $domain->id])
            ->fillForm(['price_cents' => '16.00', 'first_payment_cents' => '25.00'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(2500, $domain->refresh()->first_payment_cents);
        $this->assertSame(2500 + 6000 + 12000, $order->invoices()->sole()->total_cents);
    }

    public function test_a_paid_notice_keeps_its_amounts_when_the_price_changes(): void
    {
        $this->actingAs(User::factory()->create());
        $order = $this->place();
        $notice = $order->invoices()->sole();
        app(RecordInvoicePayment::class)->handle($notice, today()->toImmutable(), 'Bank transfer');
        $hosting = $order->services()->where('type', ServiceType::Hosting)->first();

        Livewire::test(EditService::class, ['record' => $hosting->id])
            ->assertFormFieldHidden('first_payment_cents')
            ->fillForm(['price_cents' => '45.00'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(4500, $hosting->refresh()->price_cents);
        $this->assertSame(2000 + 6000 + 12000, $notice->refresh()->total_cents);
        $this->assertSame(6000, $notice->items()->where('service_id', $hosting->id)->value('amount_cents'));
    }

    public function test_the_admin_takes_the_tax_off_an_orders_notice_and_puts_it_back(): void
    {
        config(['gaiacp.tax' => ['enabled' => true, 'inclusive' => false, 'rules' => [['name' => 'ΦΠΑ', 'rate' => 24, 'country' => null]]]]);
        $this->actingAs(User::factory()->create());
        $order = $this->place();
        $notice = $order->invoices()->sole();
        $this->assertSame(2480 + 7440 + 14880, $notice->total_cents);

        Livewire::test(ViewOrder::class, ['record' => $order->id])
            ->assertActionHasLabel('orderTax', __('Without tax'))
            ->callAction('orderTax', ['client_exempt' => false])
            ->assertNotified();

        $notice->refresh();
        $this->assertNull($notice->tax_rate);
        $this->assertSame(0, $notice->tax_cents);
        $this->assertSame(2000 + 6000 + 12000, $notice->total_cents);
        $this->assertSame(2000 + 6000 + 12000, $order->fresh()->totals()['total']);
        $this->assertSame(0, (int) $notice->items()->sum('tax_cents'));
        $this->assertFalse($this->client->refresh()->tax_exempt);

        Livewire::test(ViewOrder::class, ['record' => $order->id])
            ->assertActionHasLabel('orderTax', __('Add tax'))
            ->callAction('orderTax');

        $this->assertSame(2480 + 7440 + 14880, $notice->refresh()->total_cents);
        $this->assertSame(24.0, $notice->tax_rate);

        // Also for good: the client's later notices are without tax too.
        Livewire::test(ViewOrder::class, ['record' => $order->id])
            ->callAction('orderTax', ['client_exempt' => true]);

        $this->assertTrue($this->client->refresh()->tax_exempt);
        $this->assertSame(2000 + 6000 + 12000, $notice->refresh()->total_cents);
        Livewire::test(ViewOrder::class, ['record' => $order->id])->assertActionHidden('orderTax');
    }

    public function test_cancelling_a_pending_order(): void
    {
        $this->actingAs(User::factory()->create());
        $order = $this->place();

        Livewire::test(ViewOrder::class, ['record' => $order->id])
            ->callAction('cancelOrder');

        $this->assertSame(OrderStatus::Cancelled, $order->refresh()->status);
        $this->assertTrue($order->services()->get()->every(fn ($s) => $s->status === ServiceStatus::Cancelled));
    }

    public function test_order_pages_render(): void
    {
        $this->actingAs(User::factory()->create());
        $order = $this->place();

        $this->get('/admin/orders')->assertOk()->assertSee('Hosting Basic');
        $this->get('/admin/orders/create')->assertOk();
        $this->get("/admin/orders/{$order->id}")->assertOk()->assertSee('Complete order');
        $this->get("/admin/clients/{$this->client->id}/edit")->assertOk()->assertSee('New order');
    }

    public function test_orders_show_their_total_with_the_tax_of_their_payment_notice(): void
    {
        $this->actingAs(User::factory()->create());
        config(['gaiacp.tax.enabled' => true, 'gaiacp.tax.inclusive' => false, 'gaiacp.tax.rules' => [['name' => 'ΦΠΑ', 'rate' => 24, 'country' => null]]]);
        $order = $this->place();

        $this->assertSame(24800, $order->invoices()->sole()->total_cents);
        $this->get('/admin/orders')->assertOk()->assertSee('€248.00')->assertSee('€200.00 + ΦΠΑ 24% €48.00');
        $this->get("/admin/orders/{$order->id}")->assertOk()->assertSee('€200.00 + ΦΠΑ 24% €48.00 = €248.00 with tax');

        // The notice keeps its tax: turning tax off later changes nothing for this order.
        config(['gaiacp.tax.enabled' => false]);
        $this->assertSame(24800, $order->refresh()->totals()['total']);
    }
}
