<?php

// Merged into config by App\Plugins\PluginManager (values already in config win). Edited under Settings → Ollama.
return [
    'gaiacp.ollama' => [
        // Ollama's OpenAI-compatible API on this server or one it can reach
        'base_url' => env('GAIACP_OLLAMA_BASE_URL', 'http://localhost:11434/v1'),
        // Only for a proxy in front of Ollama that asks for one (stored encrypted); Ollama itself needs none
        'api_key' => null,
        // The model asked, as `ollama list` names it; null = the first of OllamaPlugin::suggestedModels()
        'model' => null,
    ],
];
