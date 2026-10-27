<?php

namespace App\Actions;

use App\Models\ActivityLog;
use App\Models\Server;
use App\Provisioning\ProvisionerFactory;
use App\Provisioning\ProvisioningException;

/** Servers → Log in to control panel: a fresh admin session on the server's panel. Logged without the link. */
class ServerPanelLogin
{
    public function __construct(private ProvisionerFactory $provisioners) {}

    public function url(Server $server, string $clientIp): string
    {
        try {
            $url = $this->provisioners->forServer($server)->adminLoginUrl($clientIp);
        } catch (ProvisioningException $e) {
            ActivityLog::record('provisioning.failed', "server.panel_login failed on {$server->name}: {$e->getMessage()}", properties: ['event' => 'server.panel_login', 'server_id' => $server->id]);

            throw $e;
        }

        ActivityLog::record('server.panel_login', "Control panel login on {$server->name}", properties: ['server_id' => $server->id]);

        return $url;
    }
}
