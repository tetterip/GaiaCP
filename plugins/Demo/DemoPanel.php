<?php

namespace Plugins\Demo;

use App\Enums\ServiceStatus;
use App\Enums\ServiceType;
use App\Models\Server;
use App\Models\Service;
use App\Provisioning\AccountState;
use App\Provisioning\AccountUsage;
use App\Provisioning\HostingProvisioner;
use App\Provisioning\ListsAccounts;
use App\Provisioning\PanelAccount;
use App\Provisioning\ProvisioningException;

/**
 * A demo server's "panel": an account is what GaiaCP stores about the service (pending or terminated = none,
 * suspended = suspended, else active), so creating, suspending and removing always work and change nothing else.
 */
class DemoPanel implements HostingProvisioner, ListsAccounts
{
    private const GB = 1024 * 1024 * 1024;

    public function __construct(private Server $server) {}

    public function testConnection(): string
    {
        return 'Demo panel 1.0';
    }

    public function packages(): array
    {
        return ['basic', 'pro', 'business'];
    }

    public function accountState(Service $service): AccountState
    {
        return match ($service->status) {
            ServiceStatus::Pending, ServiceStatus::Terminated, ServiceStatus::Cancelled => AccountState::Missing,
            ServiceStatus::Suspended => AccountState::Suspended,
            default => AccountState::Active,
        };
    }

    public function accountDomain(Service $service): ?string
    {
        return $this->accountState($service) === AccountState::Missing ? null : $service->domain;
    }

    public function createAccount(Service $service, string $password): void {}

    public function suspendAccount(Service $service, string $reason): void {}

    public function unsuspendAccount(Service $service): void {}

    public function terminateAccount(Service $service): void {}

    public function changePackage(Service $service, string $package): void {}

    public function changePassword(Service $service, string $password): void {}

    public function loginUrl(Service $service, string $clientIp): string
    {
        throw new ProvisioningException(__('The demo has no real control panel to log in to.'));
    }

    public function adminLoginUrl(string $clientIp): string
    {
        throw new ProvisioningException(__('The demo has no real control panel to log in to.'));
    }

    /** The same made-up numbers each time for an account, from its username. */
    public function usage(Service $service): AccountUsage
    {
        $seed = crc32((string) $service->username);
        $diskLimit = ($service->package === 'basic' ? 10 : 50) * self::GB;

        return new AccountUsage(
            diskUsed: (int) ($diskLimit * (($seed % 80) + 5) / 100),
            diskLimit: $diskLimit,
            bandwidthUsed: (int) (($seed % 40) + 1) * self::GB,
            bandwidthLimit: 100 * self::GB,
            counts: [
                'email_accounts' => [$seed % 12 + 1, null],
                'databases' => [$seed % 5 + 1, null],
                'subdomains' => [$seed % 4, null],
            ],
        );
    }

    public function accounts(): array
    {
        return Service::query()
            ->where('server_id', $this->server->id)
            ->where('type', ServiceType::Hosting)
            ->whereIn('status', [ServiceStatus::Active, ServiceStatus::Suspended])
            ->whereNotNull('username')
            ->get()
            ->map(fn (Service $service) => new PanelAccount($service->username, $service->domain, $service->package, $service->status === ServiceStatus::Suspended))
            ->values()
            ->all();
    }
}
