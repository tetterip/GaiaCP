<?php

// Merged into config by App\Plugins\PluginManager (values already in config win).
return [
    // Payments with PayPal, edited under Settings → PayPal. mode: sandbox (PayPal's test accounts, no real money) or
    // live; each has its own app (client ID + secret). No "Pay with PayPal" button until the mode in use has both.
    'gaiacp.paypal' => [
        'mode' => 'sandbox',
        'sandbox_client_id' => null,
        'sandbox_secret' => null,
        'live_client_id' => null,
        'live_secret' => null,
    ],
];
