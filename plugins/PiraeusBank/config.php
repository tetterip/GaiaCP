<?php

// Merged into config by App\Plugins\PluginManager (values already in config win).
return [
    // Card and IRIS payments through Piraeus Bank's e-commerce (epay paycenter), edited under Settings → Piraeus Bank.
    // The bank's test and live accounts use the same address; it gives separate details for each. No "Pay by card
    // (Piraeus Bank)" button until the merchant ID, POS ID, username and password are filled in.
    'gaiacp.piraeusbank' => [
        'merchant_id' => null,
        'acquirer_id' => '14',
        'pos_id' => null,
        'username' => null,
        'password' => null,
    ],
];
