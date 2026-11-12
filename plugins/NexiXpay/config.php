<?php

// Merged into config by App\Plugins\PluginManager (values already in config win).
return [
    // Card payments through Nexi XPay (Nexi Payment Gateway, NPG API), edited under Settings → Nexi XPay. mode: test
    // (Nexi's sandbox, no real money) or live; each has its own API key. No button until the mode in use has its key.
    'gaiacp.nexi_xpay' => [
        'mode' => 'test',
        'test_api_key' => null,
        'live_api_key' => null,
    ],
];
