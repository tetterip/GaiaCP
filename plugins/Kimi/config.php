<?php

// Merged into config by App\Plugins\PluginManager (values already in config win). Edited under Settings → Kimi.
return [
    'gaiacp.kimi' => [
        // An API key from platform.moonshot.ai (stored encrypted)
        'api_key' => null,
        // The model asked; null = the first of KimiPlugin::suggestedModels()
        'model' => null,
        // The API's address (not on the settings page): Moonshot's China platform is https://api.moonshot.cn/v1
        'base_url' => env('GAIACP_KIMI_BASE_URL', 'https://api.moonshot.ai/v1'),
    ],
];
