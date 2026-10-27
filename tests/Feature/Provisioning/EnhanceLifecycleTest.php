<?php

namespace Tests\Feature\Provisioning;

use App\Actions\CompareServerAccounts;
use App\Actions\ServiceLifecycle;
use App\Enums\ServiceStatus;
use App\Models\Client;
use App\Models\Server;
use App\Models\Service;
use App\Plugins\PluginManager;
use App\Provisioning\AccountState;
use App\Provisioning\ProvisionerFactory;
use App\Provisioning\ProvisioningException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Enhance: a customer organisation per client, a subscription with one website per hosting service, over its REST API. */
class EnhanceLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private const MASTER = 'aaaaaaaa-0000-0000-0000-000000000001';

    private ServiceLifecycle $lifecycle;

    private Server $server;

    /** @var array<string, array{name: string, ownerEmail: ?string, members: list<array<string, mixed>>}> */
    private array $customers = [];

    /** @var array<string, array{orgId: string, planId: int, isSuspended: bool}> */
    private array $subscriptions = [];

    /** @var array<string, array{orgId: string, domain: string, subscriptionId: int}> */
    private array $websites = [];

    /** @var array<string, array{email: string, password: string}> */
    private array $logins = [];

    /** @var list<array{0: string, 1: string, 2: array<mixed>}> method, path, body */
    private array $calls = [];

    private int $next = 1;

    private bool $failWebsites = false;

    private bool $rejectToken = false;

    protected function setUp(): void
    {
        parent::setUp();

        $plugins = app(PluginManager::class);
        $plugins->enable($plugins->find('enhance'));

        $this->fakeEnhance();
        $this->lifecycle = app(ServiceLifecycle::class);
        $this->server = Server::factory()->create([
            'type' => 'enhance', 'hostname' => 'cp.example.test', 'port' => 443, 'username' => self::MASTER, 'api_token' => 'TOKEN123',
        ]);
    }

    private function fakeEnhance(): void
    {
        Http::fake(function (Request $request) {
            $path = substr((string) parse_url($request->url(), PHP_URL_PATH), strlen('/api'));
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $body = $request->data();
            $this->calls[] = [$request->method(), $path, $body];
            $m = self::MASTER;

            if ($this->rejectToken) {
                return Http::response(['code' => 'unauthorized'], 401);
            }

            return match (true) {
                $request->method() === 'GET' && $path === "/orgs/{$m}" => Http::response(['id' => $m, 'name' => 'Hosting Ltd']),
                $request->method() === 'GET' && $path === "/orgs/{$m}/plans" => Http::response(['items' => [['id' => 1, 'name' => 'Basic'], ['id' => 2, 'name' => 'Pro']]]),
                $request->method() === 'GET' && $path === "/orgs/{$m}/customers" => Http::response(['items' => collect($this->customers)->map(fn ($c, $id) => ['id' => $id, 'name' => $c['name'], 'ownerEmail' => $c['ownerEmail']])->values()->all()]),
                $request->method() === 'POST' && $path === "/orgs/{$m}/customers" => $this->created('customers', ['name' => $body['name'], 'ownerEmail' => null, 'members' => []], uuid: true),
                $request->method() === 'POST' && $path === '/logins' => collect($this->logins)->contains('email', $body['email'])
                    ? Http::response(['code' => 'already_exists', 'detail' => 'Login exists'], 409)
                    : $this->created('logins', ['email' => $body['email'], 'password' => $body['password']], uuid: true),
                $request->method() === 'GET' && $path === '/logins' => Http::response(['items' => collect($this->logins)->map(fn ($l, $id) => ['id' => $id, 'email' => $l['email']])->values()->all()]),
                $request->method() === 'PATCH' && preg_match('#^/logins/([^/]+)$#', $path, $x) === 1 => $this->patchLogin($x[1], $body),
                default => $this->orgRoute($request->method(), $path, $query, $body),
            };
        });
    }

    private function orgRoute(string $method, string $path, array $query, array $body)
    {
        if (preg_match('#^/orgs/[^/]+/customers/([^/]+)/subscriptions$#', $path, $x)) {
            if ($method === 'POST') {
                return $this->created('subscriptions', ['orgId' => $x[1], 'planId' => $body['planId'], 'isSuspended' => false]);
            }

            return Http::response(['items' => collect($this->subscriptions)->where('orgId', $x[1])->map(fn ($s, $id) => ['id' => (int) $id, 'planId' => $s['planId'], 'isSuspended' => $s['isSuspended']])->values()->all()]);
        }

        if (preg_match('#^/orgs/([^/]+)/subscriptions/(\d+)$#', $path, $x)) {
            if (! isset($this->subscriptions[$x[2]])) {
                return Http::response(['code' => 'not_found'], 404);
            }

            return match ($method) {
                'PATCH' => tap(Http::response(null, 204), fn () => $this->subscriptions[$x[2]] = array_merge($this->subscriptions[$x[2]], $body)),
                'DELETE' => tap(Http::response(null, 204), function () use ($x) {
                    unset($this->subscriptions[$x[2]]);
                }),
                default => Http::response(['id' => (int) $x[2], ...$this->subscriptions[$x[2]], 'resources' => [
                    ['name' => 'diskSpace', 'usage' => 524288000, 'total' => 1073741824],
                    ['name' => 'mailboxes', 'usage' => 3, 'total' => 10],
                ]]),
            };
        }

        if (preg_match('#^/orgs/([^/]+)/websites$#', $path, $x)) {
            if ($method === 'POST' && $this->failWebsites) {
                return Http::response(['code' => 'domain_taken', 'detail' => 'The domain is already in use'], 409);
            }

            if ($method === 'POST') {
                return $this->created('websites', ['orgId' => $x[1], 'domain' => $body['domain'], 'subscriptionId' => $body['subscriptionId']], uuid: true);
            }

            return Http::response(['items' => collect($this->websites)
                ->where('orgId', $x[1])
                ->filter(fn ($w) => ! isset($query['subscriptionId']) || (string) $w['subscriptionId'] === $query['subscriptionId'])
                ->map(fn ($w, $id) => ['id' => $id, 'domain' => ['domain' => $w['domain']], 'subscriptionId' => $w['subscriptionId']])->values()->all()]);
        }

        if ($method === 'DELETE' && preg_match('#^/orgs/[^/]+/websites/([^/]+)$#', $path, $x)) {
            unset($this->websites[$x[1]]);

            return Http::response(null, 204);
        }

        if (preg_match('#^/orgs/([^/]+)/members$#', $path, $x)) {
            if ($method === 'POST') {
                $this->customers[$x[1]]['members'][] = ['id' => 'member-'.$this->next++, 'loginId' => $body['loginId'], 'roles' => $body['roles'], 'isActive' => true];
                $this->customers[$x[1]]['ownerEmail'] = $this->logins[$body['loginId']]['email'];

                return Http::response(['id' => 'm'], 201);
            }

            return Http::response(['items' => $this->customers[$x[1]]['members'] ?? []]);
        }

        if (preg_match('#^/orgs/([^/]+)/members/([^/]+)/sso$#', $path, $x)) {
            return Http::response(json_encode('https://cp.example.test/sso?token=abc'), 200, ['Content-Type' => 'application/json']);
        }

        return Http::response(['code' => 'unexpected', 'detail' => "{$method} {$path}"], 500);
    }

    private function created(string $table, array $row, bool $uuid = false)
    {
        $id = $uuid ? $table.'-'.$this->next++ : (string) $this->next++;
        $this->{$table}[$id] = $row;

        return Http::response(['id' => $uuid ? $id : (int) $id], 201);
    }

    private function patchLogin(string $id, array $body)
    {
        $this->logins[$id]['password'] = $body['password'];

        return Http::response(null, 204);
    }

    private function pending(array $attributes = [], ?Client $client = null): Service
    {
        return Service::factory()->for($this->server)->create([
            'client_id' => ($client ?? Client::factory()->create(['email' => 'maria@example.com', 'company' => null]))->id,
            'status' => ServiceStatus::Pending,
            'domain' => 'bakery-shop.gr',
            'username' => null,
            'package' => 'Basic',
            ...$attributes,
        ]);
    }

    public function test_connection_and_plans_with_a_bearer_token(): void
    {
        $enhance = app(ProvisionerFactory::class)->forServer($this->server);

        $this->assertSame('Enhance, Hosting Ltd, 2 plan(s)', $enhance->testConnection());
        $this->assertSame(['Basic', 'Pro'], $enhance->packages());

        Http::assertSent(fn (Request $request) => $request->url() === 'https://cp.example.test/api/orgs/'.self::MASTER
            && $request->header('Authorization')[0] === 'Bearer TOKEN123');
    }

    public function test_create_makes_the_customer_login_subscription_and_website(): void
    {
        $service = $this->pending(['domain' => 'φούρνος.gr']);

        $this->lifecycle->create($service);

        $service->refresh();
        $this->assertSame(ServiceStatus::Active, $service->status);
        $this->assertCount(1, $this->customers);
        $orgId = array_key_first($this->customers);
        $this->assertSame('maria@example.com', $this->customers[$orgId]['ownerEmail']);
        $this->assertSame($service->password, collect($this->logins)->first()['password']);
        $this->assertSame([['orgId' => $orgId, 'planId' => 1, 'isSuspended' => false]], array_values($this->subscriptions));
        $this->assertSame('xn--yxadbght4c.gr', collect($this->websites)->first()['domain']);
        $this->assertDatabaseHas('enhance_accounts', ['service_id' => $service->id, 'org_id' => $orgId, 'subscription_id' => array_key_first($this->subscriptions)]);

        // Creating again finds it there and changes nothing.
        $this->lifecycle->create($service->refresh());
        $this->assertCount(1, $this->subscriptions);
        $this->assertSame(AccountState::Active, app(ProvisionerFactory::class)->forServer($this->server)->accountState($service));
    }

    public function test_a_second_service_shares_the_customer_and_its_password(): void
    {
        $first = $this->pending();
        $this->lifecycle->create($first);

        $second = $this->pending(['domain' => 'second.gr', 'package' => 'Pro'], $first->client);
        $this->lifecycle->create($second);

        $this->assertCount(1, $this->customers);
        $this->assertCount(1, $this->logins);
        $this->assertCount(2, $this->subscriptions);
        $this->assertSame($first->refresh()->password, $second->refresh()->password);

        $this->lifecycle->changePassword($second, 'NewPassword123');

        $this->assertSame('NewPassword123', collect($this->logins)->first()['password']);
        $this->assertSame('NewPassword123', $first->refresh()->password);
    }

    public function test_a_failed_website_removes_the_subscription_again(): void
    {
        $this->failWebsites = true;
        $service = $this->pending();

        try {
            $this->lifecycle->create($service);
            $this->fail('Expected the website to fail');
        } catch (ProvisioningException $e) {
            $this->assertStringContainsString('domain_taken: The domain is already in use', $e->getMessage());
        }

        $this->assertSame([], $this->subscriptions);
        $this->assertSame(ServiceStatus::Pending, $service->refresh()->status);
        $this->assertDatabaseCount('enhance_accounts', 0);
    }

    public function test_suspend_unsuspend_change_plan_and_terminate(): void
    {
        $service = $this->pending();
        $this->lifecycle->create($service);
        $id = array_key_first($this->subscriptions);

        $this->lifecycle->suspend($service->refresh(), 'Overdue');
        $this->assertTrue($this->subscriptions[$id]['isSuspended']);
        $this->assertSame(ServiceStatus::Suspended, $service->refresh()->status);

        $this->lifecycle->unsuspend($service);
        $this->assertFalse($this->subscriptions[$id]['isSuspended']);

        $this->lifecycle->changePackage($service->refresh(), 'Pro');
        $this->assertSame(2, $this->subscriptions[$id]['planId']);

        $this->lifecycle->terminate($service->refresh());
        $this->assertSame([], $this->subscriptions);
        $this->assertSame([], $this->websites);
        $this->assertSame(ServiceStatus::Terminated, $service->refresh()->status);
        $this->assertCount(1, $this->customers); // the customer stays
    }

    public function test_sso_link_and_usage(): void
    {
        $service = $this->pending();
        $this->lifecycle->create($service);
        $enhance = app(ProvisionerFactory::class)->forServer($this->server);

        $this->assertSame('https://cp.example.test/sso?token=abc', $enhance->loginUrl($service, '198.51.100.7'));
        $this->assertSame('https://cp.example.test/', $enhance->adminLoginUrl('198.51.100.7'));

        $usage = $enhance->usage($service);
        $this->assertSame([524288000, 1073741824], [$usage->diskUsed, $usage->diskLimit]);
        $this->assertNull($usage->bandwidthUsed);
        $this->assertSame(['email_accounts' => [3, 10]], $usage->counts);
    }

    public function test_an_account_made_elsewhere_is_found_by_email_and_domain(): void
    {
        $this->customers['org-x'] = ['name' => 'Maria', 'ownerEmail' => 'maria@example.com', 'members' => []];
        $this->subscriptions['77'] = ['orgId' => 'org-x', 'planId' => 2, 'isSuspended' => true];
        $this->websites['site-x'] = ['orgId' => 'org-x', 'domain' => 'bakery-shop.gr', 'subscriptionId' => 77];
        $service = $this->pending(['status' => ServiceStatus::Active, 'username' => 'bakeryshop']);

        $enhance = app(ProvisionerFactory::class)->forServer($this->server);

        $this->assertSame(AccountState::Suspended, $enhance->accountState($service));
        $this->assertSame('bakery-shop.gr', $enhance->accountDomain($service));
        $this->assertDatabaseHas('enhance_accounts', ['service_id' => $service->id, 'org_id' => 'org-x', 'subscription_id' => '77', 'website_id' => 'site-x']);

        $accounts = $enhance->accounts();
        $this->assertCount(1, $accounts);
        $this->assertSame(['bakeryshop', 'bakery-shop.gr', 'Pro', true], [$accounts[0]->username, $accounts[0]->domain, $accounts[0]->package, $accounts[0]->suspended]);
    }

    public function test_compare_lists_subscriptions_billed_nowhere_by_domain(): void
    {
        $this->customers['org-y'] = ['name' => 'Nikos', 'ownerEmail' => 'nikos@example.com', 'members' => []];
        $this->subscriptions['5'] = ['orgId' => 'org-y', 'planId' => 1, 'isSuspended' => false];
        $this->websites['site-y'] = ['orgId' => 'org-y', 'domain' => 'unbilled.gr', 'subscriptionId' => 5];

        $comparison = app(CompareServerAccounts::class)->handle($this->server);

        $this->assertSame(['unbilled.gr'], collect($comparison['onServerOnly'])->map(fn (array $row) => $row['account']->username)->all());
    }

    public function test_a_rejected_token_says_so(): void
    {
        $this->rejectToken = true;

        try {
            app(ProvisionerFactory::class)->forServer($this->server)->testConnection();
            $this->fail('Expected a rejected token');
        } catch (ProvisioningException $e) {
            $this->assertSame('Enhance rejected the access token for cp.example.test (HTTP 401).', $e->getMessage());
        }

        $this->assertSame(0, DB::table('enhance_customers')->count());
    }
}
