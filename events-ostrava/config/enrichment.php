<?php

return [
    'mode' => env('ENRICHMENT_MODE', 'hybrid'), // ai|rules|hybrid
    'ai_enabled' => env('ENRICHMENT_AI_ENABLED', true),
    'openai_api_key' => env('ENRICHMENT_OPENAI_API_KEY', env('OPENAI_API_KEY')),
    'openai_model' => env('ENRICHMENT_OPENAI_MODEL', 'gpt-4o-mini'),
    'openai_url' => env('ENRICHMENT_OPENAI_URL', 'https://api.openai.com/v1/chat/completions'),
    'openai_timeout' => (int) env('ENRICHMENT_OPENAI_TIMEOUT', 45),
];
