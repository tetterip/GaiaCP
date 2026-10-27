<?php

// Merged into config by App\Plugins\PluginManager (values already in config win).
return [
    // Settings → Slack: the channel's incoming webhook (stored encrypted) and the events posted there
    'gaiacp.slack' => [
        'webhook_url' => null,
        'events' => ['order.placed', 'payment.recorded', 'ticket.opened', 'ticket.client_replied', 'server.down', 'server.up', 'provisioning.failed'],
    ],
];
