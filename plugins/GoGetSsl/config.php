<?php

// Merged into config by App\Plugins\PluginManager (values already in config win).
return [
    // GoGetSSL, edited under Settings → GoGetSSL: the account's API login (GoGetSSL → Reseller modules), the password
    // stored encrypted.
    'gaiacp.gogetssl' => [
        'username' => null,
        'password' => null,
        // How a new certificate's domain is validated unless the client picks another: dns, http or email.
        'dcv_method' => 'dns',
    ],
];
