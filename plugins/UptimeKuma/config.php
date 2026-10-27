<?php

// Merged into config by App\Plugins\PluginManager (values already in config win).
return [
    // Uptime Kuma, edited under Settings → Uptime Kuma. Its /metrics page is read with an API key (Kuma → Settings →
    // API Keys) as the password; `username` only for an old Kuma without API keys (its login).
    'gaiacp.uptime_kuma' => [
        'url' => null,
        'username' => null,
        'password' => null,
        'verify_tls' => true,
    ],
];
