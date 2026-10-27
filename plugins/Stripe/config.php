<?php

// Merged into config by App\Plugins\PluginManager (values already in config win).
return [
    // Card payments with Stripe Checkout, edited under Settings → Stripe. mode: test (Stripe's test cards, no real
    // money) or live; each has its own secret key and webhook signing secret. No "Pay by card" button until the
    // secret key of the mode in use is filled in.
    'gaiacp.stripe' => [
        'mode' => 'test',
        'test_secret_key' => null,
        'test_webhook_secret' => null,
        'live_secret_key' => null,
        'live_webhook_secret' => null,
    ],
];
