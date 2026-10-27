<?php

namespace Plugins\Enhance;

use App\Models\Client;
use App\Models\Server;
use App\Models\Service;
use App\Provisioning\AccountState;
use App\Provisioning\AccountUsage;
use App\Provisioning\HostingProvisioner;
use App\Provisioning\ListsAccounts;
use App\Provisioning\PanelAccount;
use App\Provisioning\ProvisioningException;
use App\Support\DomainName;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Enhance: each GaiaCP client is a customer organisation under the server's own organisation (the server's username),
 * owned by a login with the client's email; each hosting service is a subscription of that customer to a plan (the
 * service's package = the plan's name) with one website for the service's domain. Enhance knows these by ids, kept in
 * `enhance_customers` (client + server → organisation) and `enhance_accounts` (service → subscription and website).
 * A customer made elsewhere is found by its owner's email, a subscription by its website's domain.
 *
 * The client's Enhance login is one for all their services on the server: its password is the first service's, and
 * Change password changes it for all of them (their stored passwords too).
 */
class EnhanceProvisioner implements HostingProvisioner, ListsAccounts
{
    /** @var array<string, string>|null plan id => name */
    private ?array $plans = null;

    public function __construct(
        private EnhanceClient $api,
        private Server $server,
    ) {}

    public function testConnection(): string
    {
        $org = $this->api->get('/orgs/'.$this->masterOrg());

        return sprintf('Enhance, %s, %d plan(s)', is_array($org) && filled($org['name'] ?? null) ? $org['name'] : $this->masterOrg(), count($this->plans()));
    }

    public function packages(): array
    {
        return array_values(array_unique(array_values($this->plans())));
    }

    public function accountState(Service $service): AccountState
    {
        $account = $this->account($service);
        $subscription = $account ? $this->subscription($account) : null;

        return match (true) {
            $subscription === null => AccountState::Missing,
            self::isSuspended($subscription) => AccountState::Suspended,
            default => AccountState::Active,
        };
    }

    public function accountDomain(Service $service): ?string
    {
        $account = $this->account($service);

        if ($account === null || $this->subscription($account) === null) {
            return null;
        }

        $domain = $this->subscriptionWebsites($account)[0]['domain'] ?? null;

        return $service->domain && $domain === DomainName::toAscii($service->domain) ? $service->domain : $domain;
    }

    public function createAccount(Service $service, string $password): void
    {
        if (! $service->domain || ! $service->package) {
            throw new ProvisioningException('An Enhance subscription needs a domain and a plan.');
        }

        $planId = $this->planId($service->package);
        $orgId = $this->customerOrg($service, $password);

        $subscription = $this->api->post("/orgs/{$this->masterOrg()}/customers/{$orgId}/subscriptions", ['planId' => is_numeric($planId) ? (int) $planId : $planId]);
        $subscriptionId = self::id($subscription)
            ?? throw new ProvisioningException('Enhance created the subscription but did not return its id.');

        try {
            $website = $this->api->post("/orgs/{$orgId}/websites", [
                'domain' => DomainName::toAscii($service->domain),
                'subscriptionId' => is_numeric($subscriptionId) ? (int) $subscriptionId : $subscriptionId,
            ], timeout: 180);
        } catch (ProvisioningException $e) {
            // No half-made account: the next try starts again.
            rescue(fn () => $this->api->delete("/orgs/{$orgId}/subscriptions/{$subscriptionId}", ['force' => 'true']), report: false);

            throw $e;
        }

        $this->remember($service, $orgId, $subscriptionId, self::id($website));
    }

    /** Enhance's own suspension of the subscription; its reason stays in GaiaCP. */
    public function suspendAccount(Service $service, string $reason): void
    {
        $account = $this->existing($service);
        $this->api->patch("/orgs/{$account->org_id}/subscriptions/{$account->subscription_id}", ['isSuspended' => true]);
    }

    public function unsuspendAccount(Service $service): void
    {
        $account = $this->existing($service);
        $this->api->patch("/orgs/{$account->org_id}/subscriptions/{$account->subscription_id}", ['isSuspended' => false]);
    }

    /** Deletes the subscription's websites (and their files), then the subscription. The customer stays. */
    public function terminateAccount(Service $service): void
    {
        $account = $this->existing($service);

        foreach ($this->subscriptionWebsites($account) as $website) {
            $this->api->delete("/orgs/{$account->org_id}/websites/{$website['id']}");
        }

        $this->api->delete("/orgs/{$account->org_id}/subscriptions/{$account->subscription_id}", ['force' => 'true']);
        DB::table('enhance_accounts')->where('service_id', $service->id)->delete();
    }

    public function changePackage(Service $service, string $package): void
    {
        $account = $this->existing($service);
        $planId = $this->planId($package);

        $this->api->patch("/orgs/{$account->org_id}/subscriptions/{$account->subscription_id}", ['planId' => is_numeric($planId) ? (int) $planId : $planId]);
    }

    /**
     * The password of the client's Enhance login (the customer's owner), shared by all their services on this server,
     * so those keep the same stored password.
     */
    public function changePassword(Service $service, string $password): void
    {
        $account = $this->existing($service);
        $loginId = $this->owner($account->org_id)['loginId'] ?? null;

        if (blank($loginId)) {
            throw new ProvisioningException('Enhance did not say which login owns this customer.');
        }

        $this->api->patch("/logins/{$loginId}", ['password' => $password], secrets: [$password]);

        $this->siblings($service, $account->org_id)->each(fn (Service $other) => $other->update(['password' => $password]));
    }

    /** A one-time sign-in link for the customer's owner (Enhance's SSO). */
    public function loginUrl(Service $service, string $clientIp): string
    {
        $account = $this->existing($service);
        $memberId = $this->owner($account->org_id)['id'] ?? null;

        if (blank($memberId)) {
            throw new ProvisioningException('Enhance did not say which login owns this customer.');
        }

        $reply = $this->api->get("/orgs/{$account->org_id}/members/{$memberId}/sso");
        $url = is_string($reply) ? $reply : ($reply['url'] ?? $reply['loginUrl'] ?? null);

        if (! is_string($url) || ! str_starts_with(trim($url, " \"\n"), 'http')) {
            throw new ProvisioningException('Enhance did not return a sign-in link.');
        }

        return trim($url, " \"\n");
    }

    /** The control panel's login page. */
    public function adminLoginUrl(string $clientIp): string
    {
        return $this->api->baseUrl().'/';
    }

    /**
     * The subscription's resources as Enhance lists them (`resources`: name, usage, total; bytes for disk and
     * transfer). Read leniently: what isn't there stays unknown.
     */
    public function usage(Service $service): AccountUsage
    {
        $account = $this->existing($service);
        $subscription = $this->subscription($account)
            ?? throw new ProvisioningException("There is no Enhance subscription for {$service->label} on {$this->server->name}.");

        $resources = [];
        foreach ((array) ($subscription['resources'] ?? []) as $key => $resource) {
            if (is_array($resource)) {
                $name = strtolower((string) ($resource['name'] ?? $resource['type'] ?? $key));
                $resources[$name] = [self::number($resource['usage'] ?? $resource['used'] ?? null), self::number($resource['total'] ?? $resource['limit'] ?? null)];
            }
        }

        $pick = fn (string ...$names) => collect($names)->map(fn (string $name) => $resources[strtolower($name)] ?? null)->first(fn ($value) => $value !== null);

        $counts = [];
        foreach (['email_accounts' => ['mailboxes', 'emails'], 'databases' => ['mysqlDbs', 'databases'], 'sites' => ['websites']] as $key => $names) {
            if ($value = $pick(...$names)) {
                $counts[$key] = $value;
            }
        }

        $disk = $pick('diskSpace', 'disk', 'storage') ?? [null, null];
        $transfer = $pick('transfer', 'bandwidth') ?? [null, null];

        return new AccountUsage($disk[0], $disk[1] ?: null, $transfer[0], $transfer[1] ?: null, $counts);
    }

    /** Every customer's subscriptions, named by the GaiaCP service when known, else by the website's domain. */
    public function accounts(): array
    {
        $known = DB::table('enhance_accounts')
            ->join('services', 'services.id', '=', 'enhance_accounts.service_id')
            ->where('enhance_accounts.server_id', $this->server->id)
            ->pluck('services.username', 'enhance_accounts.subscription_id');

        $accounts = [];
        foreach ($this->items($this->api->get("/orgs/{$this->masterOrg()}/customers", ['limit' => 500], timeout: 60)) as $customer) {
            $orgId = (string) ($customer['id'] ?? '');

            if ($orgId === '') {
                continue;
            }

            $websites = $this->websites($orgId);

            foreach ($this->items($this->api->get("/orgs/{$this->masterOrg()}/customers/{$orgId}/subscriptions", ['limit' => 250])) as $subscription) {
                $id = (string) self::id($subscription);
                $domain = collect($websites)->firstWhere('subscription_id', $id)['domain'] ?? null;
                $planId = (string) ($subscription['planId'] ?? $subscription['plan']['id'] ?? '');

                $accounts[] = new PanelAccount(
                    username: filled($known[$id] ?? null) ? $known[$id] : ($domain ?? "subscription-{$id}"),
                    domain: $domain,
                    package: $this->plans()[$planId] ?? ($subscription['planName'] ?? $subscription['plan']['name'] ?? null),
                    suspended: self::isSuspended($subscription),
                );
            }
        }

        return $accounts;
    }

    /** The service's subscription on this server: remembered, else found by its domain under the client's customer. */
    private function account(Service $service): ?object
    {
        $account = DB::table('enhance_accounts')->where('service_id', $service->id)->where('server_id', $this->server->id)->first();

        if ($account !== null || ! $service->domain) {
            return $account;
        }

        $orgId = $this->knownCustomerOrg($service->client);
        $domain = DomainName::toAscii($service->domain);
        $website = $orgId ? collect($this->websites($orgId))->first(fn (array $w) => strcasecmp((string) $w['domain'], $domain) === 0 && $w['subscription_id'] !== null) : null;

        if ($website === null) {
            return null;
        }

        $this->remember($service, $orgId, $website['subscription_id'], $website['id']);

        return DB::table('enhance_accounts')->where('service_id', $service->id)->first();
    }

    private function existing(Service $service): object
    {
        return $this->account($service)
            ?? throw new ProvisioningException("There is no Enhance subscription for {$service->label} on {$this->server->name}.");
    }

    /** @return array<string, mixed>|null the subscription, or null when Enhance no longer has it */
    private function subscription(object $account): ?array
    {
        $subscription = $this->api->find("/orgs/{$account->org_id}/subscriptions/{$account->subscription_id}");

        return is_array($subscription) ? $subscription : null;
    }

    /**
     * The client's customer organisation, created (with its owner login: the client's email and $password) when
     * there is none. A client who already shares a customer with other services takes their password.
     */
    private function customerOrg(Service $service, string $password): string
    {
        $client = $service->client;

        if ($orgId = $this->knownCustomerOrg($client)) {
            if ($other = $this->siblings($service, $orgId)->first(fn (Service $s) => filled($s->password))) {
                $service->update(['password' => $other->password]);
            }

            return $orgId;
        }

        $name = $client->company ?: $client->name;
        $orgId = self::id($this->api->post("/orgs/{$this->masterOrg()}/customers", ['name' => $name]))
            ?? throw new ProvisioningException('Enhance created the customer but did not return its id.');

        try {
            $loginId = $this->loginFor($client, $orgId, $password);
            $this->api->post("/orgs/{$orgId}/members", ['loginId' => $loginId, 'roles' => ['Owner']]);
        } catch (ProvisioningException $e) {
            rescue(fn () => $this->api->delete("/orgs/{$orgId}"), report: false);

            throw $e;
        }

        DB::table('enhance_customers')->updateOrInsert(
            ['server_id' => $this->server->id, 'client_id' => $client->id],
            ['org_id' => $orgId, 'created_at' => now(), 'updated_at' => now()],
        );

        return $orgId;
    }

    /** A new login for the client's email, or the one Enhance already has for it. */
    private function loginFor(Client $client, string $orgId, string $password): string
    {
        try {
            $login = $this->api->post('/logins', ['email' => $client->email, 'name' => $client->name, 'password' => $password], ['orgId' => $orgId], secrets: [$password]);

            return self::id($login) ?? throw new ProvisioningException('Enhance created the login but did not return its id.');
        } catch (ProvisioningException $e) {
            $existing = collect($this->items(rescue(fn () => $this->api->get('/logins', ['limit' => 500]), [], report: false)))
                ->first(fn (array $login) => strcasecmp((string) ($login['email'] ?? ''), (string) $client->email) === 0);

            return self::id($existing ?? []) ?? throw $e;
        }
    }

    /** The client's customer on this server: remembered, else one whose owner has the client's email. */
    private function knownCustomerOrg(Client $client): ?string
    {
        $orgId = DB::table('enhance_customers')->where('server_id', $this->server->id)->where('client_id', $client->id)->value('org_id');

        if ($orgId) {
            return $orgId;
        }

        $customer = collect($this->items($this->api->get("/orgs/{$this->masterOrg()}/customers", ['limit' => 500], timeout: 60)))
            ->first(fn (array $customer) => strcasecmp((string) ($customer['ownerEmail'] ?? ''), (string) $client->email) === 0);

        if (! $customer || ! ($orgId = self::id($customer))) {
            return null;
        }

        DB::table('enhance_customers')->insert(['server_id' => $this->server->id, 'client_id' => $client->id, 'org_id' => $orgId, 'created_at' => now(), 'updated_at' => now()]);

        return $orgId;
    }

    /** @return array<string, mixed> the customer's owner (an active member with the Owner role) */
    private function owner(string $orgId): array
    {
        return collect($this->items($this->api->get("/orgs/{$orgId}/members", ['limit' => 250])))
            ->sortByDesc(fn (array $member) => (bool) ($member['isActive'] ?? true))
            ->first(fn (array $member) => in_array('Owner', (array) ($member['roles'] ?? []), true)) ?? [];
    }

    /**
     * The customer's websites (of one subscription), as id, domain and subscription_id.
     *
     * @return list<array{id: string, domain: ?string, subscription_id: ?string}>
     */
    private function websites(string $orgId, ?string $subscriptionId = null): array
    {
        $query = ['limit' => 500] + ($subscriptionId !== null ? ['subscriptionId' => $subscriptionId] : []);

        return collect($this->items($this->api->get("/orgs/{$orgId}/websites", $query)))
            ->map(fn (array $website) => [
                'id' => (string) self::id($website),
                'domain' => ($domain = $website['domain']['domain'] ?? $website['domain'] ?? null) && is_string($domain) ? strtolower($domain) : null,
                'subscription_id' => isset($website['subscriptionId']) ? (string) $website['subscriptionId'] : null,
            ])
            ->filter(fn (array $website) => $website['id'] !== '' && ($subscriptionId === null || $website['subscription_id'] === $subscriptionId))
            ->values()
            ->all();
    }

    /**
     * The subscription's websites; the remembered one when Enhance's list doesn't say which subscription each is on.
     *
     * @return list<array{id: string, domain: ?string, subscription_id: ?string}>
     */
    private function subscriptionWebsites(object $account): array
    {
        $websites = $this->websites($account->org_id, $account->subscription_id);

        if ($websites === [] && $account->website_id) {
            $website = $this->api->find("/orgs/{$account->org_id}/websites/{$account->website_id}");
            $domain = is_array($website) ? ($website['domain']['domain'] ?? $website['domain'] ?? null) : null;
            $websites = is_array($website) ? [['id' => (string) $account->website_id, 'domain' => is_string($domain) ? strtolower($domain) : null, 'subscription_id' => (string) $account->subscription_id]] : [];
        }

        return $websites;
    }

    /** @return array<string, string> plan id => name */
    private function plans(): array
    {
        return $this->plans ??= collect($this->items($this->api->get("/orgs/{$this->masterOrg()}/plans", timeout: 15)))
            ->filter(fn (array $plan) => filled($plan['id'] ?? null) && filled($plan['name'] ?? null))
            ->mapWithKeys(fn (array $plan) => [(string) $plan['id'] => (string) $plan['name']])
            ->all();
    }

    private function planId(string $name): string
    {
        $id = array_search($name, $this->plans(), true);

        return $id === false
            ? throw new ProvisioningException("Enhance has no plan named {$name} on {$this->server->name}.")
            : (string) $id;
    }

    /** The client's other services with a subscription under the same customer here. */
    /** @return Collection<int, Service> */
    private function siblings(Service $service, string $orgId): Collection
    {
        $ids = DB::table('enhance_accounts')->where('server_id', $this->server->id)->where('org_id', $orgId)->where('service_id', '!=', $service->id)->pluck('service_id');

        return Service::query()->whereKey($ids)->get();
    }

    private function remember(Service $service, string $orgId, string $subscriptionId, ?string $websiteId): void
    {
        DB::table('enhance_accounts')->updateOrInsert(
            ['service_id' => $service->id],
            ['server_id' => $this->server->id, 'org_id' => $orgId, 'subscription_id' => $subscriptionId, 'website_id' => $websiteId, 'created_at' => now(), 'updated_at' => now()],
        );
    }

    private function masterOrg(): string
    {
        return (string) $this->server->username
            ?: throw new ProvisioningException('The Enhance server needs its organisation ID as the username.');
    }

    /**
     * @param  array<mixed>|string|null  $reply
     * @return list<array<string, mixed>>
     */
    private function items(array|string|null $reply): array
    {
        $items = is_array($reply) ? ($reply['items'] ?? (array_is_list($reply) ? $reply : [])) : [];

        return array_values(array_filter((array) $items, 'is_array'));
    }

    /** @param  array<mixed>|string|null  $reply */
    private static function id(array|string|null $reply): ?string
    {
        $id = is_array($reply) ? ($reply['id'] ?? null) : null;

        return is_scalar($id) && (string) $id !== '' ? (string) $id : null;
    }

    /** @param  array<string, mixed>  $subscription */
    private static function isSuspended(array $subscription): bool
    {
        return (bool) ($subscription['isSuspended'] ?? $subscription['suspended'] ?? false)
            || filled($subscription['suspendedBy'] ?? null)
            || strtolower((string) ($subscription['status'] ?? '')) === 'suspended';
    }

    private static function number(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
