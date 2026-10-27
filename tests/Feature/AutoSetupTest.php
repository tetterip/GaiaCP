<?php

namespace Tests\Feature;

use App\Actions\CompleteOrder;
use App\Actions\PlaceOrder;
use App\Actions\RecordOrderPayment;
use App\Actions\RecordPayment;
use App\Enums\AutoSetup;
use App\Enums\BillingCycle;
use App\Enums\OrderStatus;
use App\Enums\ServiceStatus;
use App\Models\Client;
use App\Models\Order;
use App\Models\Product;
use App\Models\Server;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Plugins\EasyGr\EasyRegistrar;
use Tests\FakesEasy;
use Tests\FakesWhm;
use Tests\TestCase;

class AutoSetupTest extends TestCase
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

    /** A hosting order of a product with the given auto-setup choice. */
    private function order(AutoSetup $autoSetup): Order
    {
        $product = Product::factory()->priced(6000)->create(['type' => 'hosting', 'package' => 'basic', 'auto_setup' => $autoSetup]);
        $product->servers()->attach($this->server);

        return app(PlaceOrder::class)->handle($this->client, [
            ['product_id' => $product->id, 'type' => 'hosting', 'label' => 'Hosting', 'domain' => 'acme.gr', 'server_id' => $this->server->id,
                'package' => 'basic', 'price_cents' => 6000, 'billing_cycle' => BillingCycle::Annually],
        ]);
    }

    public function test_the_default_waits_for_complete_order(): void
    {
        $order = $this->order(AutoSetup::OnAccept);
        $service = $order->services()->sole();

        $this->assertSame(AutoSetup::OnAccept, $service->auto_setup);
        $this->assertSame(ServiceStatus::Pending, $service->status);
        $this->assertSame([], $this->whmCalls);

        app(RecordOrderPayment::class)->handle($order, CarbonImmutable::parse('2026-10-09'), 'bank_transfer');
        $this->assertSame(ServiceStatus::Pending, $service->refresh()->status);

        $this->assertSame([], app(CompleteOrder::class)->handle($order));
        $this->assertSame(ServiceStatus::Active, $service->refresh()->status);
        $this->assertSame(OrderStatus::Completed, $order->refresh()->status);
    }

    public function test_set_up_as_soon_as_the_order_is_placed(): void
    {
        $order = $this->order(AutoSetup::OnOrder);

        $this->assertSame(ServiceStatus::Active, $order->services()->sole()->status);
        $this->assertArrayHasKey($order->services()->sole()->username, $this->whmAccounts);
        $this->assertSame(OrderStatus::Completed, $order->status);
    }

    public function test_a_failed_setup_on_order_keeps_the_order(): void
    {
        $this->whmFailures['createacct'] = 'Disk quota exceeded';

        $placer = app(PlaceOrder::class);
        $product = Product::factory()->priced(6000)->create(['type' => 'hosting', 'auto_setup' => AutoSetup::OnOrder]);
        $order = $placer->handle($this->client, [
            ['product_id' => $product->id, 'type' => 'hosting', 'label' => 'Hosting', 'domain' => 'acme.gr', 'server_id' => $this->server->id,
                'package' => 'basic', 'price_cents' => 6000, 'billing_cycle' => BillingCycle::Annually],
        ]);

        $service = $order->services()->sole();
        $this->assertSame(ServiceStatus::Pending, $service->status);
        $this->assertSame([$service->id], array_keys($placer->setupErrors));
        $this->assertSame(OrderStatus::Pending, $order->status);
    }

    public function test_set_up_on_the_first_payment(): void
    {
        $order = $this->order(AutoSetup::OnPayment);
        $service = $order->services()->sole();
        $this->assertSame(ServiceStatus::Pending, $service->status);

        app(RecordPayment::class)->handle($service, 6000, CarbonImmutable::parse('2026-10-09'), 'bank_transfer');

        $this->assertSame(ServiceStatus::Active, $service->refresh()->status);
        $this->assertSame('2027-10-09', $service->next_due_date->toDateString());
        $this->assertSame(OrderStatus::Completed, $order->refresh()->status);
    }

    public function test_a_domain_is_registered_on_the_order_payment(): void
    {
        $product = Product::factory()->priced(1800)->create(['type' => 'domain', 'auto_setup' => AutoSetup::OnPayment]);
        $order = app(PlaceOrder::class)->handle($this->client, [
            ['product_id' => $product->id, 'type' => 'domain', 'domain' => 'acme.gr', 'price_cents' => 1800, 'billing_cycle' => BillingCycle::Annually],
        ]);

        $recorder = app(RecordOrderPayment::class);
        $recorder->handle($order, CarbonImmutable::parse('2026-10-09'), 'bank_transfer');

        $this->assertSame([], $recorder->setupErrors);
        $this->assertSame(ServiceStatus::Active, $order->services()->sole()->status);
        $this->assertContains(EasyRegistrar::REGISTER, $this->easyActions());
    }

    public function test_never_is_left_for_the_account_menu(): void
    {
        $order = $this->order(AutoSetup::Never);

        $failures = app(CompleteOrder::class)->handle($order);

        $this->assertCount(1, $failures);
        $this->assertSame([], $this->whmCalls);
        $this->assertSame(OrderStatus::Pending, $order->refresh()->status);
    }
}
