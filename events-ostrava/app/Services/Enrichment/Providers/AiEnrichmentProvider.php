<?php

declare(strict_types=1);

namespace App\Services\Enrichment\Providers;

use App\DTO\EnrichmentResult;
use App\Models\Event;
use App\Models\EventEnrichmentLog;
use App\Services\Enrichment\Contracts\EnrichmentProviderInterface;
use App\Services\Enrichment\Contracts\LlmClientInterface;
use Illuminate\Support\Facades\Log;

final readonly class AiEnrichmentProvider implements EnrichmentProviderInterface
{
    public function __construct(private LlmClientInterface $client) {}

    /**
     * @throws \Throwable
     * @throws \JsonException
     */
    public function enrich(Event $event, string $reason = 'ai'): EnrichmentResult
    {
        $prompt = $this->buildPrompt($event);

        $logEntry = EventEnrichmentLog::create([
            'event_id' => $event->id,
            'mode' => 'ai',
            'prompt' => $prompt,
            'status' => 'pending',
        ]);

        $startedAt = microtime(true);

        try {
            $content = $this->client->complete($prompt);
            $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

            $parsed = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($parsed)) {
                $this->markLogFailed($logEntry, $durationMs, $content);
                throw new \RuntimeException('Invalid JSON from LLM.');
            }

            $logEntry->update([
                'response' => $content,
                'status' => 'success',
                'duration_ms' => $durationMs,
            ]);

            return new EnrichmentResult(
                logId: $logEntry->id,
                fields: $this->normalizeFields($parsed),
                mode: 'ai'
            );
        } catch (\Throwable $e) {
            if ($logEntry->status === 'pending') {
                $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
                $this->markLogFailed($logEntry, $durationMs, $e->getMessage());
            }
            Log::error('AiEnrichmentProvider error', [
                'event_id' => $event->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * @throws \JsonException
     */
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
            'short_summary (string, max 200 chars),',
            'title_en (string, English translation of the title),',
            'title_uk (string, Ukrainian translation of the title),',
            'short_summary_en (string, English translation of the short_summary, max 200 chars),',
            'short_summary_uk (string, Ukrainian translation of the short_summary, max 200 chars).',
            '',
            'If unsure, use null or "unknown". Keep summaries factual and concise.',
            'Translations must preserve the original meaning. If the title is already in the target language, repeat it as-is.',
            '',
            \json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
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

        $titleI18n = $this->buildI18nMap(
            $this->normalizeTranslation($parsed['title_en'] ?? null),
            $this->normalizeTranslation($parsed['title_uk'] ?? null),
        );
        $shortSummaryI18n = $this->buildI18nMap(
            $this->normalizeSummary($parsed['short_summary_en'] ?? null),
            $this->normalizeSummary($parsed['short_summary_uk'] ?? null),
        );

        return [
            'kid_friendly' => $this->normalizeBool($parsed['is_kid_friendly'] ?? null),
            'age_min' => $this->normalizeInt($parsed['age_min'] ?? null, 0, 120),
            'age_max' => $this->normalizeInt($parsed['age_max'] ?? null, 0, 120),
            'indoor_outdoor' => $indoorOutdoor,
            'category' => $category,
            'language' => $language,
            'short_summary' => $this->normalizeSummary($parsed['short_summary'] ?? null),
            'title_i18n' => $titleI18n,
            'short_summary_i18n' => $shortSummaryI18n,
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
            return (bool) $value;
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

    private function normalizeTranslation(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $text = trim($value);

        return $text === '' ? null : $text;
    }

    /**
     * @return array<string, string>|null
     */
    private function buildI18nMap(?string $en, ?string $uk): ?array
    {
        $map = array_filter(['en' => $en, 'uk' => $uk]);

        return $map === [] ? null : $map;
    }

    private function markLogFailed(
        EventEnrichmentLog $logEntry,
        int $durationMs,
        ?string $content = null,
    ): void {
        $logEntry->update([
            'response' => $content,
            'status' => 'failed',
            'duration_ms' => $durationMs,
            'error' => mb_substr('Invalid JSON response', 0, 1000),
        ]);
    }
}
