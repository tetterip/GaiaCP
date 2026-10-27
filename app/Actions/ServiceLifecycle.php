<?php

namespace App\Actions;

use App\Enums\MailTemplate;
use App\Enums\ServiceStatus;
use App\Mail\ClientMailer;
use App\Models\ActivityLog;
use App\Models\Service;
use App\Provisioning\AccountState;
use App\Provisioning\AccountUsage;
use App\Provisioning\ControlsPower;
use App\Provisioning\HostingProvisioner;
use App\Provisioning\OffersShortcuts;
use App\Provisioning\ProvisionerFactory;
use App\Provisioning\ProvisioningException;
use App\Provisioning\UsernameGenerator;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Create / suspend / unsuspend / terminate / change package.
 *
 * Hosting services with a server go through the panel API; every other
 * service (domain, maintenance, …) only changes status here. Each method
 * checks the current state first, so calling it twice is harmless, and
 * every outcome, including failures, lands in the activity log. A failed
 * API call leaves the service unchanged and throws ProvisioningException.
 * Creating, suspending and unsuspending email the client (when that template is on).
 */
class ServiceLifecycle
{
    /** How long a usage reading is kept before the panel is asked again. */
    public const USAGE_MINUTES = 15;

    public function __construct(
        private ProvisionerFactory $provisioners,
        private UsernameGenerator $usernames,
        private ClientMailer $mailer,
    ) {}

    /**
     * Create the account on the service's server. A pending service becomes active and gets the
     * welcome email. An active or suspended one is created again, for when it was moved to another
     * server: the old server is not touched, a suspended one is suspended on the new server too,
     * and the welcome email goes out only when asked.
     */
    public function create(Service $service, bool $sendWelcome = true): void
    {
        if (! $this->onPanel($service)) {
            throw new ProvisioningException('Only hosting services with a server can be created on a panel.');
        }

        if (! in_array($service->status, [ServiceStatus::Pending, ServiceStatus::Active, ServiceStatus::Suspended], true)) {
            throw new ProvisioningException('Only pending, active or suspended services can be created.');
        }

        $wasPending = $service->status === ServiceStatus::Pending;

        $service->username = $service->username ?: $this->usernames->forService($service);
        $service->password = $service->password ?: Str::password(16, symbols: false);
        $service->save();

        $this->panel($service, 'account.created', function (HostingProvisioner $panel) use ($service) {
            if ($panel->accountState($service) === AccountState::Missing) {
                $panel->createAccount($service, $service->password);

                if ($service->status === ServiceStatus::Suspended) {
                    $panel->suspendAccount($service, (string) $service->suspension_reason);
                }

                return;
            }

            // A retry after a timeout finds our own account already there; anything else
            // belongs to someone else (same cPanel username, or the domain under another Plesk customer).
            $domain = $panel->accountDomain($service);

            if (strcasecmp((string) $domain, (string) $service->domain) !== 0) {
                throw new ProvisioningException("{$service->server->name} already has a different account for this service ({$domain}). Change the username or check the server.");
            }
        });

        if ($wasPending) {
            $service->update(['status' => ServiceStatus::Active]);
        }

        ActivityLog::record('account.created', "Account {$service->username} created on {$service->server->name}", $service);

        if ($wasPending || $sendWelcome) {
            $this->mailer->hostingWelcome($service);
        }
    }

    /**
     * Suspend on the panel and here. Also allowed on a service already marked suspended,
     * since the status field can be edited by hand: the panel is checked either way.
     */
    public function suspend(Service $service, string $reason): void
    {
        if (! in_array($service->status, [ServiceStatus::Active, ServiceStatus::Suspended], true)) {
            throw new ProvisioningException('Only active or suspended services can be suspended.');
        }

        $acted = false;

        if ($this->onPanel($service)) {
            $acted = $this->panel($service, 'account.suspended', function (HostingProvisioner $panel) use ($service, $reason) {
                if ($panel->accountState($service) !== AccountState::Active) {
                    return false;
                }

                $panel->suspendAccount($service, $reason);

                return true;
            });
        }

        $wasActive = $service->status === ServiceStatus::Active;

        if (! $wasActive && ! $acted) {
            return; // suspended on both sides already
        }

        $service->update(['status' => ServiceStatus::Suspended, 'suspension_reason' => $reason]);
        ActivityLog::record('service.suspended', "Suspended: {$reason}", $service);

        if ($wasActive) {
            $this->mailer->service(MailTemplate::ServiceSuspension, $service, ['suspension_reason' => $reason]);
        }
    }

    /** Unsuspend on the panel and here; like suspend(), also on a service already marked active. */
    public function unsuspend(Service $service): void
    {
        if (! in_array($service->status, [ServiceStatus::Active, ServiceStatus::Suspended], true)) {
            return;
        }

        $acted = false;

        if ($this->onPanel($service)) {
            $acted = $this->panel($service, 'account.unsuspended', function (HostingProvisioner $panel) use ($service) {
                if ($panel->accountState($service) !== AccountState::Suspended) {
                    return false;
                }

                $panel->unsuspendAccount($service);

                return true;
            });
        }

        $wasSuspended = $service->status === ServiceStatus::Suspended;

        if (! $wasSuspended && ! $acted) {
            return; // active on both sides already
        }

        $service->update(['status' => ServiceStatus::Active, 'suspension_reason' => null]);
        ActivityLog::record('service.unsuspended', 'Unsuspended', $service);

        if ($wasSuspended) {
            $this->mailer->service(MailTemplate::ServiceUnsuspension, $service);
        }
    }

    public function terminate(Service $service): void
    {
        if (in_array($service->status, [ServiceStatus::Terminated, ServiceStatus::Cancelled], true)) {
            return;
        }

        // A pending service was never created on the panel.
        if ($this->onPanel($service) && $service->status !== ServiceStatus::Pending) {
            $this->panel($service, 'account.terminated', function (HostingProvisioner $panel) use ($service) {
                if ($panel->accountState($service) !== AccountState::Missing) {
                    $panel->terminateAccount($service);
                }
            });
        }

        $service->update(['status' => ServiceStatus::Terminated, 'suspension_reason' => null]);
        ActivityLog::record('service.terminated', 'Terminated'.($this->onPanel($service) ? "; account {$service->username} removed" : ''), $service);
    }

    /** Set a new login password on the panel, then store it (encrypted). The password itself is never logged. */
    public function changePassword(Service $service, string $password): void
    {
        if (! $this->onPanel($service)) {
            throw new ProvisioningException('Only hosting services with a server have a panel password.');
        }

        if (! in_array($service->status, [ServiceStatus::Active, ServiceStatus::Suspended], true)) {
            throw new ProvisioningException('Only active or suspended accounts can change password.');
        }

        $this->panel($service, 'account.password_changed', fn (HostingProvisioner $panel) => $panel->changePassword($service, $password));

        $service->update(['password' => $password]);
        ActivityLog::record('account.password_changed', "Password of {$service->username} changed on {$service->server->name}", $service);
    }

    /**
     * A one-time control panel login link for an active account (the client area's "Log in to …"). Logged without
     * the link, which carries the session.
     */
    public function loginUrl(Service $service, string $clientIp, ?string $shortcut = null): string
    {
        if (! $this->onPanel($service) || $service->status !== ServiceStatus::Active) {
            throw new ProvisioningException('Only active hosting services with a server have a panel login.');
        }

        $url = $this->panel($service, 'account.panel_login', function (HostingProvisioner $panel) use ($service, $clientIp, $shortcut) {
            if ($shortcut === null) {
                return $panel->loginUrl($service, $clientIp);
            }

            if (! $panel instanceof OffersShortcuts || ! array_key_exists($shortcut, $panel->shortcuts())) {
                throw new ProvisioningException("The panel of {$service->server->name} has no shortcut {$shortcut}.");
            }

            return $panel->shortcutUrl($service, $shortcut, $clientIp);
        });

        ActivityLog::record('account.panel_login', "Panel login link for {$service->username} created on {$service->server->name}".($shortcut !== null ? " (shortcut: {$shortcut})" : ''), $service);

        return $url;
    }

    /**
     * Disk space and bandwidth of an active or suspended account, read from the panel at most every USAGE_MINUTES
     * ($fresh reads it now). Only a reading, so it is not logged; a failure throws ProvisioningException.
     */
    public function usage(Service $service, bool $fresh = false): AccountUsage
    {
        if (! $this->onPanel($service) || ! in_array($service->status, [ServiceStatus::Active, ServiceStatus::Suspended], true)) {
            throw new ProvisioningException('Only active or suspended hosting services with a server have usage.');
        }

        $key = "service-usage:{$service->id}";

        if ($fresh) {
            Cache::forget($key);
        }

        // Kept as an array: the cache unserializes no objects (cache.serializable_classes).
        return AccountUsage::fromArray(Cache::remember($key, now()->addMinutes(self::USAGE_MINUTES), fn () => $this->provisioners->forService($service)
            ->usage($service)
            ->readAt(CarbonImmutable::now())
            ->toArray()));
    }

    /**
     * Start, shut down, restart or stop an active account's machine (ControlsPower: a Proxmox VPS). A suspended one
     * stays off: unsuspending starts it.
     */
    public function power(Service $service, string $action): void
    {
        if (! $this->onPanel($service) || $service->status !== ServiceStatus::Active) {
            throw new ProvisioningException('Only active hosting services with a server can be started or stopped.');
        }

        if (! in_array($action, ControlsPower::ACTIONS, true)) {
            throw new ProvisioningException("Unknown power action {$action}.");
        }

        $this->panel($service, 'account.power', function (HostingProvisioner $panel) use ($service, $action) {
            if (! $panel instanceof ControlsPower) {
                throw new ProvisioningException("The panel of {$service->server->name} cannot start or stop accounts.");
            }

            $panel->power($service, $action);
        });

        ActivityLog::record('account.power', "Power {$action} for {$service->username} on {$service->server->name}", $service, properties: ['action' => $action]);
    }

    /** Whether the account's machine is running, or null when its panel has no power control or cannot be asked. */
    public function isRunning(Service $service): ?bool
    {
        if (! $this->onPanel($service)) {
            return null;
        }

        return rescue(function () use ($service) {
            $panel = $this->provisioners->forService($service);

            return $panel instanceof ControlsPower ? $panel->isRunning($service) : null;
        }, null, report: false);
    }

    public function changePackage(Service $service, string $package): void
    {
        if ($package === $service->package) {
            return;
        }

        if (! in_array($service->status, [ServiceStatus::Active, ServiceStatus::Suspended], true)) {
            throw new ProvisioningException('Only active or suspended services can change package.');
        }

        if ($this->onPanel($service)) {
            $this->panel($service, 'account.package_changed', fn (HostingProvisioner $panel) => $panel->changePackage($service, $package));
        }

        $old = $service->package;
        $service->update(['package' => $package]);
        ActivityLog::record('service.package_changed', "Package changed from {$old} to {$package}", $service);
    }

    /** Set the panel's package to the service's own again (Compare with the server, when the two differ). */
    public function applyPackage(Service $service): void
    {
        if (! $this->onPanel($service) || blank($service->package) || ! in_array($service->status, [ServiceStatus::Active, ServiceStatus::Suspended], true)) {
            throw new ProvisioningException('Only an active or suspended hosting account with a package can have it set on the server.');
        }

        $this->panel($service, 'account.package_changed', fn (HostingProvisioner $panel) => $panel->changePackage($service, $service->package));
        ActivityLog::record('service.package_changed', "Package {$service->package} set on the server again", $service);
    }

    private function onPanel(Service $service): bool
    {
        return $service->type->needsServer() && $service->server_id !== null;
    }

    /**
     * @template T
     *
     * @param  Closure(HostingProvisioner): T  $call
     * @return T
     */
    private function panel(Service $service, string $event, Closure $call): mixed
    {
        try {
            return $call($this->provisioners->forService($service));
        } catch (ProvisioningException $e) {
            ActivityLog::record('provisioning.failed', "{$event} failed: {$e->getMessage()}", $service, properties: ['event' => $event]);

            throw $e;
        }
    }
}
