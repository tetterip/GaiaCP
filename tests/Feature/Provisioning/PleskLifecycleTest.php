<?php

namespace Tests\Feature\Provisioning;

use App\Actions\ServiceLifecycle;
use App\Enums\ServiceStatus;
use App\Models\Client;
use App\Models\Server;
use App\Models\Service;
use App\Provisioning\PackageOptions;
use App\Provisioning\ProvisionerFactory;
use App\Provisioning\ProvisioningException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\FakesPlesk;
use Tests\TestCase;

class PleskLifecycleTest extends TestCase
{
    use FakesPlesk, RefreshDatabase;

    private ServiceLifecycle $lifecycle;

    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakePlesk();
        $this->lifecycle = app(ServiceLifecycle::class);
        $this->server = Server::factory()->plesk()->create();
    }

    private function pending(array $attributes = []): Service
    {
        return Service::factory()->for($this->server)->create([
            'status' => ServiceStatus::Pending,
            'domain' => 'bakery.gr',
            'username' => null,
            'package' => 'Starter',
            ...$attributes,
        ]);
    }

    public function test_connection_and_service_plans(): void
    {
        $plesk = app(ProvisionerFactory::class)->forServer($this->server);

        $this->assertSame('Plesk 18.0.70', $plesk->testConnection());
        $this->assertSame(['Starter', 'Business'], $plesk->packages());
        $this->assertSame(['Starter', 'Business'], PackageOptions::forServer($this->server->id));
    }

    public function test_create_makes_the_customer_and_the_subscription(): void
    {
        $service = $this->pending();

        $this->lifecycle->create($service);

        $service->refresh();
        $this->assertSame(ServiceStatus::Active, $service->status);
        $this->assertSame('bakery', $service->username);

        $customer = $this->pleskCustomers[array_key_first($this->pleskCustomers)];
        $this->assertSame(['login' => 'bakery', 'external_id' => "gaiacp-client-{$service->client_id}"], $customer);

        $ws = $this->pleskWebspaces['bakery.gr'];
        $this->assertSame([array_key_first($this->pleskCustomers), 0, 'Starter', 'bakery', '203.0.113.5'], [$ws['owner'], $ws['status'], $ws['plan'], $ws['ftp_login'], $ws['ip']]);

        $add = collect($this->pleskCalls)->firstWhere(0, 'webspace.add')[1];
        $this->assertSame(['name', 'owner-id', 'htype', 'ip_address'], array_map(fn ($e) => $e->getName(), iterator_to_array($add->gen_setup->children(), false)));
        $this->assertSame(['gen_setup', 'hosting', 'plan-name'], array_map(fn ($e) => $e->getName(), iterator_to_array($add->children(), false)));
        $this->assertSame($service->password, (string) $add->hosting->vrt_hst->property[1]->value);
    }

    public function test_second_subscription_reuses_the_clients_customer(): void
    {
        $client = Client::factory()->create();
        $this->lifecycle->create($this->pending(['client_id' => $client->id]));
        $this->lifecycle->create($this->pending(['client_id' => $client->id, 'domain' => 'bakery-shop.gr']));

        $this->assertCount(1, $this->pleskCustomers);
        $this->assertCount(2, $this->pleskWebspaces);
        $this->assertSame(1, collect($this->pleskOperations())->filter(fn ($o) => $o === 'customer.add')->count());
    }

    public function test_configured_ip_is_used_instead_of_detection(): void
    {
        $this->server->update(['shared_ip' => '198.51.100.9']);

        $this->lifecycle->create($this->pending());

        $this->assertSame('198.51.100.9', $this->pleskWebspaces['bakery.gr']['ip']);
        $this->assertNotContains('ip.get', $this->pleskOperations());
    }

    public function test_create_retry_finds_its_own_subscription(): void
    {
        $service = $this->pending(['username' => 'bakery']);
        $this->pleskCustomers[50] = ['login' => 'bakery', 'external_id' => "gaiacp-client-{$service->client_id}"];
        $this->pleskWebspaces['bakery.gr'] = ['owner' => 50, 'status' => 0, 'plan' => 'Starter', 'ftp_login' => 'bakery', 'ip' => '203.0.113.5'];

        $this->lifecycle->create($service);

        $this->assertNotContains('webspace.add', $this->pleskOperations());
        $this->assertSame(ServiceStatus::Active, $service->fresh()->status);
    }

    public function test_create_refuses_a_domain_owned_by_another_customer(): void
    {
        $service = $this->pending(['username' => 'bakery']);
        $this->pleskCustomers[50] = ['login' => 'someone', 'external_id' => null];
        $this->pleskWebspaces['bakery.gr'] = ['owner' => 50, 'status' => 0, 'plan' => 'Starter', 'ftp_login' => 'x', 'ip' => '203.0.113.5'];

        try {
            $this->lifecycle->create($service);
            $this->fail('expected an exception');
        } catch (ProvisioningException $e) {
            $this->assertStringContainsString('another Plesk customer', $e->getMessage());
        }

        $this->assertSame(ServiceStatus::Pending, $service->fresh()->status);
    }

    public function test_suspend_unsuspend_change_plan_terminate(): void
    {
        $service = $this->pending();
        $this->lifecycle->create($service);
        $service->refresh();

        $this->lifecycle->suspend($service, Service::SUSPENDED_OVERDUE);
        $this->assertSame(16, $this->pleskWebspaces['bakery.gr']['status']);
        $this->assertSame(ServiceStatus::Suspended, $service->fresh()->status);

        $this->lifecycle->unsuspend($service->fresh());
        $this->assertSame(0, $this->pleskWebspaces['bakery.gr']['status']);

        $this->lifecycle->changePackage($service->fresh(), 'Business');
        $this->assertSame('Business', $this->pleskWebspaces['bakery.gr']['plan']);
        $this->assertSame('guid-business', (string) collect($this->pleskCalls)->firstWhere(0, 'webspace.switch-subscription')[1]->{'plan-guid'});

        $this->lifecycle->terminate($service->fresh());
        $this->assertArrayNotHasKey('bakery.gr', $this->pleskWebspaces);
        $this->assertCount(1, $this->pleskCustomers, 'the customer account stays');
        $this->assertSame(ServiceStatus::Terminated, $service->fresh()->status);
    }

    public function test_unknown_plan_is_reported(): void
    {
        $service = $this->pending();
        $this->lifecycle->create($service);

        $this->expectExceptionMessage('There is no service plan "Gold"');

        $this->lifecycle->changePackage($service->fresh(), 'Gold');
    }

    public function test_plesk_errors_surface_and_leave_the_service_pending(): void
    {
        $this->pleskFailures['webspace.add'] = [1019, 'Invalid domain name.'];
        $service = $this->pending();

        try {
            $this->lifecycle->create($service);
            $this->fail('expected an exception');
        } catch (ProvisioningException $e) {
            $this->assertSame('Plesk webspace add: Invalid domain name. (error 1019)', $e->getMessage());
        }

        $this->assertSame(ServiceStatus::Pending, $service->fresh()->status);
    }

    public function test_change_password_sets_the_subscription_system_user(): void
    {
        $service = $this->pending();
        $this->lifecycle->create($service);

        $this->lifecycle->changePassword($service->refresh(), 'NewSecret123');

        $this->assertSame('NewSecret123', $this->pleskWebspaces['bakery.gr']['ftp_password']);
        $this->assertSame('NewSecret123', $service->refresh()->password);
        $this->assertDatabaseMissing('activity_logs', ['description' => 'NewSecret123']);
    }
}
