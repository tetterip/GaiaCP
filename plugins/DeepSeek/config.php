<?php

// Merged into config by App\Plugins\PluginManager (values already in config win). Edited under Settings → DeepSeek.
return [
    'gaiacp.deepseek' => [
        // An API key from platform.deepseek.com (stored encrypted)
        'api_key' => null,
        // The model asked; null = the first of DeepSeekPlugin::suggestedModels()
        'model' => null,
        // The API's address (not on the settings page)
        'base_url' => 'https://api.deepseek.com',
    ],
];
