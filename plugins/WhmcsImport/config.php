<?php

// Merged into config by App\Plugins\PluginManager (values already in config win).
return [
    // A WHMCS database to import from (gaiacp:import-whmcs, Settings → Import from WHMCS). Only read.
    'database.connections.whmcs' => [
        'driver' => env('WHMCS_DB_CONNECTION', 'mysql'),
        'host' => env('WHMCS_DB_HOST', '127.0.0.1'),
        'port' => env('WHMCS_DB_PORT', '3306'),
        'database' => env('WHMCS_DB_DATABASE', 'whmcs'),
        'username' => env('WHMCS_DB_USERNAME', 'root'),
        'password' => env('WHMCS_DB_PASSWORD', ''),
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
        'prefix' => '',
        'strict' => false,
        'encryption_hash' => env('WHMCS_ENCRYPTION_HASH'),
        // Optional: WHMCS's API, for the passwords only WHMCS itself can decrypt (DecryptPassword).
        'api_url' => env('WHMCS_API_URL'),
        'api_identifier' => env('WHMCS_API_IDENTIFIER'),
        'api_secret' => env('WHMCS_API_SECRET'),
    ],
];
