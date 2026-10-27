<?php

namespace App\Provisioning;

use App\Models\Service;

/**
 * A HostingProvisioner whose panel can open one of its pages already signed in (cPanel, Plesk): the client area's
 * Quick shortcuts (HostingSelfService::shortcuts(), PanelLoginController ?to=).
 */
interface OffersShortcuts
{
    /** @return array<string, array{label: string, icon: string}> key => translated label and a Heroicon name, in display order */
    public function shortcuts(): array;

    /** A one-time sign-in that opens the shortcut's page; short-lived, never stored or logged. */
    public function shortcutUrl(Service $service, string $key, string $clientIp): string;
}
