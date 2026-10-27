<?php

namespace App\Provisioning;

use App\Models\Service;

/**
 * One implementation per panel type. Methods throw ProvisioningException on
 * failure. Callers (app/Actions/Provisioning) handle state checks, status
 * changes and logging; implementations only talk to the panel.
 */
interface HostingProvisioner
{
    /** Human-readable confirmation, e.g. "WHM 11.130.0.4". */
    public function testConnection(): string;

    /** @return list<string> package / service plan names */
    public function packages(): array;

    public function accountState(Service $service): AccountState;

    /** The main domain of the account with this service's username, or null if there is none. */
    public function accountDomain(Service $service): ?string;

    public function createAccount(Service $service, string $password): void;

    public function suspendAccount(Service $service, string $reason): void;

    public function unsuspendAccount(Service $service): void;

    public function terminateAccount(Service $service): void;

    public function changePackage(Service $service, string $package): void;

    /** The account's login password (cPanel user; Plesk subscription system user). */
    public function changePassword(Service $service, string $password): void;

    /**
     * A one-time link that signs the account's owner in to the control panel (client area "Log in to …"). Short-lived;
     * never stored or logged. $clientIp is the browser's address, for panels that tie the session to it.
     */
    public function loginUrl(Service $service, string $clientIp): string;

    /**
     * A one-time link that signs the server's own login (its username) in to the panel's admin side (Servers → Log in
     * to control panel), or the panel's login page where there is no such link. Short-lived; never stored or logged.
     */
    public function adminLoginUrl(string $clientIp): string;

    /** Disk space and this month's bandwidth of the account, used and allowed. */
    public function usage(Service $service): AccountUsage;
}
