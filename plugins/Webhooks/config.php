<?php

// Merged into config by App\Plugins\PluginManager (values already in config win).
return [
    // Endpoints and their secrets are rows of webhook_endpoints (Settings → Webhooks); these are the sending rules.
    'gaiacp.webhooks' => [
        'timeout' => 10, // seconds a receiver has to answer
        'retry_minutes' => [1, 5, 30, 120, 720], // waits before each new try; after the last one the delivery failed
        'alert_after' => 5, // failed tries in a row before the bell tells the super admins (once)
        'disable_after' => 50, // failed tries in a row before the endpoint is switched off
        'per_run' => 100, // deliveries sent per scheduler run (every minute)
        // URLs on loopback, private, link-local or reserved addresses are refused unless this is on (WebhookAddress)
        'allow_private_addresses' => (bool) env('GAIACP_WEBHOOKS_ALLOW_PRIVATE', false),
    ],
];
