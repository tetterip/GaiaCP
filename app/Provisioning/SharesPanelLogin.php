<?php

namespace App\Provisioning;

use App\Models\Service;

/**
 * A HostingProvisioner whose panel signs the client in with one login shared by all their accounts there (Plesk: one
 * customer, made by the client's first service), so an account's own username is not always the panel login. Change
 * password offers to change that login too (ServiceLifecycle::changePassword()).
 */
interface SharesPanelLogin
{
    /**
     * The panel login of the account's owner when it is not the service's own username (a shared login the account's
     * own password does not change), else null.
     */
    public function sharedLogin(Service $service): ?string;

    /** Set that shared login's password. */
    public function changeSharedLoginPassword(Service $service, string $password): void;
}
