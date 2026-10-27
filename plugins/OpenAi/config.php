<?php

// Merged into config by App\Plugins\PluginManager (values already in config win). Edited under Settings → ChatGPT.
return [
    'gaiacp.openai' => [
        // An API key from platform.openai.com (stored encrypted)
        'api_key' => null,
        // The model asked; null = the first of OpenAiPlugin::suggestedModels()
        'model' => null,
        // The API's address (not on the settings page), e.g. for a proxy that speaks the same API
        'base_url' => env('GAIACP_OPENAI_BASE_URL', 'https://api.openai.com/v1'),
    ],
];
