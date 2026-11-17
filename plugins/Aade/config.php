<?php

// Merged into config by App\Plugins\PluginManager (values already in config win).
return [
    // ΑΑΔΕ registry lookup by ΑΦΜ (RgWsPublic2) for the client form: the special access codes (ειδικοί κωδικοί
    // πρόσβασης) and optionally the ΑΦΜ the lookups are made for. Edited under Settings → ΑΑΔΕ.
    'gaiacp.aade' => [
        'enabled' => false, // off: no lookup button on the client form
        'username' => null,
        'password' => null,
        'afm_called_by' => null,
    ],
];
