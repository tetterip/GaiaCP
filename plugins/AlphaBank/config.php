<?php

// Merged into config by App\Plugins\PluginManager (values already in config win).
return [
    // Card payments through Alpha Bank e-Commerce (Cardlink), edited under Settings → Alpha Bank. mode: test (the bank's
    // test environment, no real money) or live. No "Pay by card (Alpha Bank)" button until the merchant ID and shared
    // secret are filled in.
    'gaiacp.alphabank' => [
        'mode' => 'test',
        'merchant_id' => null,
        'shared_secret' => null,
    ],
];
