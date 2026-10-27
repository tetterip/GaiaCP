<?php

// Merged into config by App\Plugins\PluginManager (values already in config win). Edited under Settings → VIES VAT check.
return [
    'gaiacp.vies' => [
        // Your EU country (ISO code, e.g. GR); null = Settings → General → Region's country
        'home_country' => null,
        // Your VAT number without the country prefix, sent as the requester so VIES gives a consultation number;
        // null = Settings → Company's VAT number
        'requester_vat' => null,
        // No tax for an EU business in another EU country whose VAT number VIES found valid (intra-EU reverse charge)
        'reverse_charge' => false,
        // How long a valid check counts for the reverse charge, in days
        'valid_days' => 365,
        // Printed on those payment notices; null = the built-in text in the client's language
        'reverse_charge_note' => null,
    ],
];
