<?php

declare(strict_types=1);

namespace App\Services\Enrichment\Providers;

use App\DTO\EnrichmentResult;
use App\Models\Event;
use App\Models\EventEnrichmentLog;
use App\Services\Enrichment\Contracts\EnrichmentProviderInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class AiEnrichmentProvider implements EnrichmentProviderInterface
{
    public function enrich(Event $event, string $reason = 'ai'): EnrichmentResult
    {
        $model = (string) config('enrichment.openai_model', 'gpt-4o-mini');
        $apiKey = config('enrichment.openai_api_key');
        if (!$apiKey) {
            throw new \RuntimeException('Missing ENRICHMENT_OPENAI_API_KEY (or OPENAI_API_KEY)');
        }

        $prompt = $this->buildPrompt($event);
        $logEntry = EventEnrichmentLog::create([
            'event_id' => $event->id,
            'mode' => 'ai',
            'prompt' => $prompt,
            'status' => 'pending',
        ]);

        $startedAt = microtime(true);

        try {
            $response = Http::timeout((int) config('enrichment.openai_timeout', 45))
                ->withToken($apiKey)
                ->post((string) config('enrichment.openai_url', 'https://api.openai.com/v1/chat/completions'), [
                    'model' => $model,
                    'temperature' => 0.2,
                    'response_format' => ['type' => 'json_object'],
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => 'Return ONLY valid JSON. No markdown. No extra keys.',
                        ],
                        [
                            'role' => 'user',
                            'content' => $prompt,
                        ],
                    ],
                ]);

            $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
            $body = $response->json();
            $content = data_get($body, 'choices.0.message.content');

            if (!$response->ok() || !$content) {
                $error = $response->body();
                $this->markLogFailed($logEntry, $durationMs, $error, $body);
                throw new \RuntimeException('OpenAI enrichment failed.');
            }

            $parsed = json_decode($content, true);
            if (!is_array($parsed)) {
                $this->markLogFailed($logEntry, $durationMs, 'Invalid JSON response', $body, $content);
                throw new \RuntimeException('Invalid JSON from LLM.');
            }

            $logEntry->update([
                'response' => $content,
                'status' => 'success',
                'tokens_prompt' => data_get($body, 'usage.prompt_tokens'),
                'tokens_completion' => data_get($body, 'usage.completion_tokens'),
                'duration_ms' => $durationMs,
            ]);

            return new EnrichmentResult(
                logId: $logEntry->id,
                fields: $this->normalizeFields($parsed),
                mode: 'ai'
            );
        } catch (\Throwable $e) {
            Log::error('AiEnrichmentProvider error', [
                'event_id' => $event->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    private function buildPrompt(Event $event): string
    {
        $payload = [
            'title' => $event->title,
            'description_raw' => $event->description_raw ?? $event->description,
            'start_at' => optional($event->start_at)->toIso8601String(),
            'end_at' => optional($event->end_at)->toIso8601String(),
            'location_name' => $event->location_name ?? $event->venue,
            'source_url' => $event->source_url,
        ];

        return implode("\n", [
            'You are enriching a family event in Ostrava. Output JSON only with keys:',
            'is_kid_friendly (boolean or null), age_min (int or null), age_max (int or null),',
            'indoor_outdoor ("indoor","outdoor","both","unknown"),',
            'category ("culture","sports","education","nature","theatre","music","festival","workshop","exhibition","other"),',
            'language ("cs","en","mixed","unknown"),',
            'short_summary (string, max 200 chars).',
            '',
            'If unsure, use null or "unknown". Keep summaries factual and concise.',
            '',
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    private function normalizeFields(array $parsed): array
    {
        $indoorOutdoor = $this->normalizeEnum($parsed['indoor_outdoor'] ?? null, [
            'indoor', 'outdoor', 'both', 'unknown',
        ]);
        $category = $this->normalizeEnum($parsed['category'] ?? null, [
            'culture', 'sports', 'education', 'nature', 'theatre', 'music', 'festival', 'workshop', 'exhibition', 'other',
        ]);
        $language = $this->normalizeEnum($parsed['language'] ?? null, [
            'cs', 'en', 'mixed', 'unknown',
        ]);

        return [
            'kid_friendly' => $this->normalizeBool($parsed['is_kid_friendly'] ?? null),
            'age_min' => $this->normalizeInt($parsed['age_min'] ?? null, 0, 120),
            'age_max' => $this->normalizeInt($parsed['age_max'] ?? null, 0, 120),
            'indoor_outdoor' => $indoorOutdoor,
            'category' => $category,
            'language' => $language,
            'short_summary' => $this->normalizeSummary($parsed['short_summary'] ?? null),
        ];
    }

    private function normalizeBool(mixed $value): ?bool
    {
        if ($value === null) {
            return null;
        }
        if (is_bool($value)) {
            return $value;
        }
        if (is_string($value)) {
            $value = strtolower(trim($value));
            if ($value === 'true' || $value === 'yes' || $value === '1') {
                return true;
            }
            if ($value === 'false' || $value === 'no' || $value === '0') {
                return false;
            }
        }
        if (is_int($value)) {
            return $value === 1 ? true : ($value === 0 ? false : null);
        }

        return null;
    }

    private function normalizeInt(mixed $value, int $min, int $max): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric($value)) {
            return null;
        }
        $intValue = (int) $value;
        if ($intValue < $min || $intValue > $max) {
            return null;
        }

        return $intValue;
    }

    private function normalizeEnum(mixed $value, array $allowed): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = strtolower(trim($value));

        return in_array($value, $allowed, true) ? $value : null;
    }

    private function normalizeSummary(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $summary = trim($value);
        if ($summary === '') {
            return null;
        }
        if (mb_strlen($summary) > 200) {
            $summary = mb_substr($summary, 0, 200);
        }

        return $summary;
    }

    private function markLogFailed(
        EventEnrichmentLog $logEntry,
        int $durationMs,
        string $error,
        ?array $body = null,
        ?string $content = null
    ): void {
        $logEntry->update([
            'response' => $content ?: ($body ? json_encode($body) : null),
            'status' => 'failed',
            'duration_ms' => $durationMs,
            'error' => mb_substr($error, 0, 1000),
        ]);
    }
}
