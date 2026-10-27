<?php

// Merged into config by App\Plugins\PluginManager (values already in config win).
return [
    // IP blacklist check, edited under Settings → IP blacklist check: the DNS blacklists (DNSBL zones) each server's
    // IP is looked up in, one per line. Spamhaus answers only through a resolver of your own, not Google or
    // Cloudflare's public ones; an answer like that counts as not checked, never as listed.
    'gaiacp.blacklists' => [
        'zones' => "zen.spamhaus.org\nbl.spamcop.net\nb.barracudacentral.org\npsbl.surriel.com",
    ],
];
