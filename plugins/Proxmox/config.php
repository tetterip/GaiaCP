<?php

// Merged into config by App\Plugins\PluginManager (values already in config win).
return [
    // Proxmox VE, edited under Settings → Proxmox. ci_user: the cloud-init user that gets the service's password
    // (root by default); client_role: the Proxmox role the client's own user gets on their VPS; client_power: Start,
    // Shut down and Restart on the client's service page; storage: where full clones go (empty = the template's);
    // nameserver: DNS servers for cloud-init (empty = the node's).
    'gaiacp.proxmox' => [
        'ci_user' => 'root',
        'client_role' => 'PVEVMUser',
        'client_power' => true,
        'storage' => null,
        'nameserver' => null,
    ],
];
