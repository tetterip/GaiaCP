<?php

// Merged into config by App\Plugins\PluginManager (values already in config win).
return [
    // Card payments through Mastercard Gateway (MPGS hosted checkout), edited under Settings → Mastercard Gateway.
    // host: the gateway the bank's account is on (eu, ap, na, or custom with custom_host, for a bank's own address).
    // mode: test (the TEST merchant ID the bank gave, no real money) or live; each has its merchant ID and API password.
    // display_name: shown to clients on the button, "Pay by card (<name>)" (the bank's name, say).
    'gaiacp.mastercard' => [
        'display_name' => null,
        'host' => 'eu',
        'custom_host' => null,
        'mode' => 'test',
        'test_merchant_id' => null,
        'test_api_password' => null,
        'live_merchant_id' => null,
        'live_api_password' => null,
    ],
];
