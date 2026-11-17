<?php

// Merged into config by App\Plugins\PluginManager (values already in config win).
return [
    // IRIS payments, edited under Settings → IRIS. number: the VAT number, mobile number or alias the bank linked to
    // IRIS; qr: the fixed QR code image the bank gave (a file on the local disk, under iris/). No "Pay with IRIS"
    // button until there is a number or a QR code.
    'gaiacp.iris' => [
        'payee' => null,
        'number' => null,
        'qr' => null,
        'note' => null,
    ],
];
