<?php

// Merged into config by App\Plugins\PluginManager (values already in config win).
return [
    // Site checks, edited under Settings → Site checks: a certificate with fewer days left than this is a problem.
    // Let's Encrypt renews 30 days ahead, so 14 days left means its renewal is failing.
    'gaiacp.site_checks' => [
        'ssl_warn_days' => 14,
    ],
];
