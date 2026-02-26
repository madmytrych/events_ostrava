<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Bot\TelegramBotService;
use App\Services\Enrichment\Contracts\LlmClientInterface;
use App\Services\Enrichment\EnrichmentService;
use App\Services\Enrichment\LlmClients\GeminiLlmClient;
use App\Services\Enrichment\LlmClients\OpenAiLlmClient;
use App\Services\Enrichment\Providers\AiEnrichmentProvider;
use App\Services\Enrichment\Providers\RulesEnrichmentProvider;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(TelegramBotService::class, function () {
            return new TelegramBotService;
        });

        $this->app->singleton(EnrichmentService::class, function ($app) {
            return new EnrichmentService(
                $app->make(AiEnrichmentProvider::class),
                $app->make(RulesEnrichmentProvider::class),
            );
        });

        $this->app->bind(LlmClientInterface::class, function () {
            return match ((string) config('enrichment.ai_provider', 'gemini')) {
                'openai' => new OpenAiLlmClient(
                    apiKey: (string) config('enrichment.openai_api_key'),
                    model: (string) config('enrichment.openai_model', 'gpt-4o-mini'),
                    url: (string) config('enrichment.openai_url', 'https://api.openai.com/v1/chat/completions'),
                    timeout: (int) config('enrichment.openai_timeout', 45),
                ),
                default => new GeminiLlmClient(
                    apiKey: (string) config('enrichment.gemini_api_key'),
                    model: (string) config('enrichment.gemini_model', 'gemini-2.0-flash-lite'),
                    timeout: (int) config('enrichment.gemini_timeout', 45),
                ),
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
