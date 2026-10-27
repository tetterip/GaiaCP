<?php

namespace App\Actions;

use App\Enums\ServiceStatus;
use App\Enums\ServiceType;
use App\Models\ActivityLog;
use App\Models\Server;
use App\Models\Service;
use App\Provisioning\AccountState;
use App\Provisioning\PanelAccount;
use App\Provisioning\ProvisionerFactory;
use App\Provisioning\ProvisioningException;
use InvalidArgumentException;

/**
 * One-click fixes on Servers → Compare with the server (CompareServerAccounts): make one side like the other. Fixes
 * on the server go through ServiceLifecycle (the panel is checked first); fixes in GaiaCP only change the service and
 * email nobody. Each is logged. Deleting an account on the server is never offered here.
 */
class FixServerDifference
{
    /** Suspended on the server, active in GaiaCP */
    public const UNSUSPEND_ON_SERVER = 'unsuspend_on_server';

    public const MARK_SUSPENDED = 'mark_suspended';

    /** Active on the server, suspended in GaiaCP */
    public const SUSPEND_ON_SERVER = 'suspend_on_server';

    public const MARK_ACTIVE = 'mark_active';

    /** Another package */
    public const TAKE_PACKAGE = 'take_package';

    public const APPLY_PACKAGE = 'apply_package';

    /** Another domain (changing it on the server is left to the panel) */
    public const TAKE_DOMAIN = 'take_domain';

    /** Missing on the server */
    public const CREATE_ON_SERVER = 'create_on_server';

    public const MARK_TERMINATED = 'mark_terminated';

    public function __construct(private ServiceLifecycle $lifecycle, private ProvisionerFactory $provisioners) {}

    /**
     * The fixes for one difference CompareServerAccounts::differences() reported.
     *
     * @return list<string>
     */
    public static function forDifference(string $difference): array
    {
        return match ($difference) {
            'Suspended on the server, active in GaiaCP' => [self::UNSUSPEND_ON_SERVER, self::MARK_SUSPENDED],
            'Active on the server, suspended in GaiaCP' => [self::SUSPEND_ON_SERVER, self::MARK_ACTIVE],
            'Package' => [self::TAKE_PACKAGE, self::APPLY_PACKAGE],
            'Domain' => [self::TAKE_DOMAIN],
            default => [],
        };
    }

    /** Whether the fix changes the server (the rest change only GaiaCP). */
    public static function onServer(string $fix): bool
    {
        return in_array($fix, [self::UNSUSPEND_ON_SERVER, self::SUSPEND_ON_SERVER, self::APPLY_PACKAGE, self::CREATE_ON_SERVER], true);
    }

    /**
     * @param  PanelAccount|null  $account  the server's side, for the fixes that take its value
     *
     * @throws ProvisioningException when the panel call fails (nothing is changed then)
     */
    public function handle(Service $service, string $fix, ?PanelAccount $account = null): void
    {
        if ($service->type !== ServiceType::Hosting) {
            throw new InvalidArgumentException('Only hosting accounts are compared with a server.');
        }

        $server = $service->server?->name ?? 'the server';

        match ($fix) {
            self::UNSUSPEND_ON_SERVER => $this->lifecycle->unsuspend($service),
            self::SUSPEND_ON_SERVER => $this->lifecycle->suspend($service, $service->suspension_reason ?: 'Suspended in GaiaCP'),
            self::APPLY_PACKAGE => $this->lifecycle->applyPackage($service),
            self::CREATE_ON_SERVER => $this->lifecycle->create($service, sendWelcome: false),
            self::MARK_SUSPENDED => $this->mark($service, ['status' => ServiceStatus::Suspended, 'suspension_reason' => $service->suspension_reason ?: 'Suspended on the server'], "Marked suspended, as on {$server}"),
            self::MARK_ACTIVE => $this->mark($service, ['status' => ServiceStatus::Active, 'suspension_reason' => null], "Marked active, as on {$server}"),
            self::MARK_TERMINATED => $this->mark($service, ['status' => ServiceStatus::Terminated, 'suspension_reason' => null], "Marked terminated: its account is not on {$server}"),
            self::TAKE_PACKAGE => $this->mark($service, ['package' => $this->value($account?->package)], "Package set to {$account?->package}, as on {$server}"),
            self::TAKE_DOMAIN => $this->mark($service, ['domain' => $this->value($account?->domain)], "Domain set to {$account?->domain}, as on {$server}"),
            default => throw new InvalidArgumentException("Unknown fix {$fix}."),
        };
    }

    /**
     * Suspend an account GaiaCP doesn't bill (only on the server). Nothing happens when it isn't running.
     *
     * @throws ProvisioningException
     */
    public function suspendUnbilled(Server $server, PanelAccount $account): void
    {
        // The panels find an account by username (Plesk by domain); a service that isn't saved anywhere carries both
        $probe = (new Service)->forceFill(['type' => ServiceType::Hosting, 'username' => $account->username, 'domain' => $account->domain, 'server_id' => $server->id]);
        $probe->setRelation('server', $server);

        $panel = $this->provisioners->forServer($server);
        if ($panel->accountState($probe) !== AccountState::Active) {
            return;
        }

        $panel->suspendAccount($probe, 'Not billed');
        ActivityLog::record('server.account_suspended', "{$server->name}: {$account->username} suspended (not billed in GaiaCP)", properties: ['server_id' => $server->id]);
    }

    /** @param  array<string, mixed>  $values */
    private function mark(Service $service, array $values, string $description): void
    {
        $service->update($values);
        ActivityLog::record('service.synced_from_server', $description, $service);
    }

    private function value(?string $value): string
    {
        return filled($value) ? $value : throw new InvalidArgumentException('The server gave no value to take.');
    }
}
