<?php

namespace App\Provisioning;

use App\Models\Service;

/**
 * A HostingProvisioner whose panel manages WordPress (WP Toolkit on cPanel and Plesk): the client area's WordPress
 * section lists the account's sites, opens one's dashboard already signed in and installs a new one. Each method throws
 * ProvisioningException when the panel refuses or has no WordPress manager.
 */
interface ManagesWordPress
{
    /**
     * The WordPress sites of the account's main domain and its subdomains (never another account's).
     *
     * @return list<WordPressSite>
     */
    public function wordPressSites(Service $service): array;

    /** A one-time link that opens the site's dashboard (wp-admin) signed in as its administrator; never stored or logged. */
    public function wordPressLoginUrl(Service $service, WordPressSite $site, string $clientIp): string;

    /** Starts installing WordPress on the account's main domain (under $install->path); it may finish a little later. */
    public function installWordPress(Service $service, WordPressInstall $install): void;
}
