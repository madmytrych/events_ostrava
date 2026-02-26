<?php

return [
    'mode' => env('ENRICHMENT_MODE', 'ai'), // ai|rules|hybrid
    'ai_enabled' => env('ENRICHMENT_AI_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | AI Provider
    |--------------------------------------------------------------------------
    | Which LLM backend to use for AI enrichment: gemini, openai
    */
    'ai_provider' => env('ENRICHMENT_AI_PROVIDER', 'gemini'),

    /*
    |--------------------------------------------------------------------------
    | Gemini
    |--------------------------------------------------------------------------
    | Free tier via Google AI Studio (no billing required).
    | gemini-2.0-flash-lite: up to 30 RPM free.
    */
    'gemini_api_key' => env('ENRICHMENT_GEMINI_API_KEY'),
    'gemini_model' => env('ENRICHMENT_GEMINI_MODEL', 'gemini-2.5-flash'),
    'gemini_timeout' => (int) env('ENRICHMENT_GEMINI_TIMEOUT', 45),

    /*
    |--------------------------------------------------------------------------
    | OpenAI (legacy)
    |--------------------------------------------------------------------------
    */
    'openai_api_key' => env('ENRICHMENT_OPENAI_API_KEY', env('OPENAI_API_KEY')),
    'openai_model' => env('ENRICHMENT_OPENAI_MODEL', 'gpt-4o-mini'),
    'openai_url' => env('ENRICHMENT_OPENAI_URL', 'https://api.openai.com/v1/chat/completions'),
    'openai_timeout' => (int) env('ENRICHMENT_OPENAI_TIMEOUT', 45),
];
