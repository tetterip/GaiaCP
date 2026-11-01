<?php

// Merged into config by App\Plugins\PluginManager (values already in config win).
return [
    // "Continue with Google" in the client area: an OAuth client ("Web application") from Google Cloud Console →
    // APIs & Services → Credentials. Edited under Settings → Google sign-in. No button until both are filled in.
    'gaiacp.google_login' => [
        'client_id' => null,
        'client_secret' => null,
        // Admins may log in to /admin with Google too (an existing active admin with that address; two-factor still asked)
        'admins' => false,
    ],
];
