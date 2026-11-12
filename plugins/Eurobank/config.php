<?php

// Merged into config by App\Plugins\PluginManager (values already in config win).
return [
    // Card payments through Eurobank (Cardlink), edited under Settings → Eurobank. mode: test (the bank's
    // test environment, no real money) or live. No "Pay by card (Eurobank)" button until the merchant ID and shared
    // secret are filled in.
    'gaiacp.eurobank' => [
        'mode' => 'test',
        'merchant_id' => null,
        'shared_secret' => null,
    ],
];
