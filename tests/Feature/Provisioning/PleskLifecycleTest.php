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
use Illuminate\Support\Facades\Cache;
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
        $this->assertSame(['198.51.100.9'], $this->subscriptionIps(), 'a link-local fe80:: is no address for a website');
    }

    public function test_a_shared_ipv6_is_given_to_new_subscriptions_too(): void
    {
        $this->pleskIps = ['fe80::1' => 'shared', '2001:db8::9' => 'exclusive', '203.0.113.5' => 'shared', '2001:db8::5' => 'shared', '2001:db8::6' => 'shared'];

        $this->lifecycle->create($this->pending());

        $this->assertSame(['203.0.113.5', '2001:db8::5'], $this->subscriptionIps());
        $add = collect($this->pleskCalls)->firstWhere(0, 'webspace.add')[1];
        $this->assertSame(['name', 'owner-id', 'htype', 'ip_address', 'ip_address'], array_map(fn ($e) => $e->getName(), iterator_to_array($add->gen_setup->children(), false)));
    }

    public function test_a_configured_ipv4_gets_the_shared_ipv6_and_a_configured_ipv6_stays_alone(): void
    {
        $this->pleskIps = ['203.0.113.5' => 'shared', '2001:db8::5' => 'shared'];
        $this->server->update(['shared_ip' => '198.51.100.9']);
        $this->lifecycle->create($this->pending());
        $this->assertSame(['198.51.100.9', '2001:db8::5'], $this->subscriptionIps());

        $this->pleskCalls = [];
        $this->server->update(['shared_ip' => '2001:db8::77']);
        $this->lifecycle->create($this->pending(['domain' => 'bakery-shop.gr']));
        $this->assertSame(['2001:db8::77'], $this->subscriptionIps());
        $this->assertNotContains('ip.get', $this->pleskOperations());
    }

    public function test_a_configured_ipv4_is_used_when_the_addresses_cannot_be_read(): void
    {
        $this->pleskFailures['ip.get'] = [1006, 'Permission denied'];
        $this->server->update(['shared_ip' => '198.51.100.9']);

        $this->lifecycle->create($this->pending());

        $this->assertSame(['198.51.100.9'], $this->subscriptionIps());
    }

    /** @return list<string> the addresses of the last webspace.add, in gen_setup and in vrt_hst (which must agree) */
    private function subscriptionIps(): array
    {
        $add = collect($this->pleskCalls)->where(0, 'webspace.add')->last()[1];
        $ips = array_map('strval', iterator_to_array($add->gen_setup->ip_address, false));
        $this->assertSame($ips, array_map('strval', iterator_to_array($add->hosting->vrt_hst->ip_address, false)));

        return $ips;
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
        // This service made the client's Plesk login, so that password changes too.
        $this->assertSame('NewSecret123', collect($this->pleskCustomers)->firstWhere('login', $service->username)['passwd']);
        $this->assertSame('NewSecret123', $service->refresh()->password);
        $this->assertDatabaseMissing('activity_logs', ['description' => 'NewSecret123']);
    }

    public function test_change_password_of_a_second_subscription_leaves_the_plesk_login_alone(): void
    {
        $service = $this->pending();
        $this->lifecycle->create($service);
        $id = array_key_first($this->pleskCustomers);
        $this->pleskCustomers[$id]['login'] = 'firstservice';

        $this->lifecycle->changePassword($service->refresh(), 'NewSecret123');

        $this->assertSame('NewSecret123', $this->pleskWebspaces['bakery.gr']['ftp_password']);
        $this->assertArrayNotHasKey('passwd', $this->pleskCustomers[$id]);
        $this->assertNotContains('customer.set', $this->pleskOperations());
    }

    public function test_the_shared_plesk_login_changes_only_when_asked(): void
    {
        $service = $this->pending();
        $this->lifecycle->create($service);
        $id = array_key_first($this->pleskCustomers);
        $this->assertNull($this->lifecycle->sharedLogin($service->refresh()));

        $this->pleskCustomers[$id]['login'] = 'firstservice';
        Cache::flush();
        $this->assertSame('firstservice', $this->lifecycle->sharedLogin($service));

        $this->lifecycle->changePassword($service, 'NewSecret123', sharedLogin: true);

        $this->assertSame('NewSecret123', $this->pleskCustomers[$id]['passwd']);
        $this->assertSame('NewSecret123', $this->pleskWebspaces['bakery.gr']['ftp_password']);
        $this->assertDatabaseHas('activity_logs', ['event' => 'account.password_changed', 'description' => "Password of {$service->username} changed on {$service->server->name}, and of the panel login firstservice"]);
    }

    public function test_a_refused_plesk_login_password_changes_nothing(): void
    {
        $service = $this->pending();
        $this->lifecycle->create($service);
        $old = $service->refresh()->password;
        $this->pleskFailures['customer.set'] = [1019, 'The password is too simple.'];

        try {
            $this->lifecycle->changePassword($service, 'NewSecret123');
            $this->fail('expected an exception');
        } catch (ProvisioningException $e) {
            $this->assertStringContainsString('too simple', $e->getMessage());
        }

        $this->assertArrayNotHasKey('ftp_password', $this->pleskWebspaces['bakery.gr']);
        $this->assertSame($old, $service->refresh()->password);
    }

    public function test_a_subscription_moved_to_another_client_gets_no_plesk_login_of_the_old_client(): void
    {
        $service = $this->pending();
        $this->lifecycle->create($service);
        $id = array_key_first($this->pleskCustomers);
        // Moved to another client: the subscription stays under the old client's Plesk customer
        $service->refresh()->update(['client_id' => Client::factory()->create()->id]);
        $this->pleskCustomers[$id]['login'] = 'oldclient';
        Cache::flush();

        $this->assertNull($this->lifecycle->sharedLogin($service->refresh()));

        try {
            $this->lifecycle->loginUrl($service, '198.51.100.7');
            $this->fail('expected an exception');
        } catch (ProvisioningException $e) {
            $this->assertStringContainsString('another client', $e->getMessage());
        }

        $this->lifecycle->changePassword($service, 'NewSecret123', sharedLogin: true);

        $this->assertSame('NewSecret123', $this->pleskWebspaces['bakery.gr']['ftp_password']);
        $this->assertArrayNotHasKey('passwd', $this->pleskCustomers[$id]);
    }

    public function test_a_plesk_customer_made_by_hand_is_shared_only_when_it_holds_no_other_clients_subscription(): void
    {
        $service = $this->pending();
        $this->lifecycle->create($service);
        $id = array_key_first($this->pleskCustomers);
        $this->pleskCustomers[$id] = ['login' => 'byhand', 'external_id' => null];
        Cache::flush();

        $this->assertSame('byhand', $this->lifecycle->sharedLogin($service->refresh()));

        // The same Plesk customer also owns a subscription GaiaCP bills to someone else
        $this->pleskWebspaces['other.gr'] = ['owner' => $id, 'status' => 0, 'plan' => 'Starter', 'ftp_login' => 'other', 'ip' => '203.0.113.5'];
        Service::factory()->for($this->server)->create(['status' => ServiceStatus::Active, 'domain' => 'other.gr', 'username' => 'other']);
        Cache::flush();

        $this->assertNull($this->lifecycle->sharedLogin($service));
        $this->expectException(ProvisioningException::class);
        $this->lifecycle->loginUrl($service, '198.51.100.7');
    }
}
