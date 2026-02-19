<?php

declare(strict_types=1);

namespace App\Services\Enrichment\Providers;

use App\DTO\EnrichmentResult;
use App\Models\Event;
use App\Models\EventEnrichmentLog;
use App\Services\Enrichment\Contracts\EnrichmentProviderInterface;

final class RulesEnrichmentProvider implements EnrichmentProviderInterface
{
    public function enrich(Event $event, string $reason = 'rules'): EnrichmentResult
    {
        $input = [
            'title' => $event->title,
            'description_raw' => $event->description_raw ?? $event->description,
            'location_name' => $event->location_name ?? $event->venue,
        ];

        $fields = $this->deriveFields($input);

        $logEntry = EventEnrichmentLog::create([
            'event_id' => $event->id,
            'mode' => 'rules',
            'prompt' => json_encode(['reason' => $reason, 'input' => $input], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'response' => json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'status' => $reason === 'fallback' ? 'fallback' : 'success',
        ]);

        return new EnrichmentResult(
            logId: $logEntry->id,
            fields: $fields,
            mode: 'rules'
        );
    }

    private function deriveFields(array $input): array
    {
        $text = $this->normalizeText(($input['title'] ?? '').' '.($input['description_raw'] ?? ''));

        [$ageMin, $ageMax] = $this->extractAgeRange($text);
        $kidFriendly = $this->detectKidFriendly($text, $ageMin, $ageMax);

        return [
            'kid_friendly' => $kidFriendly,
            'age_min' => $ageMin,
            'age_max' => $ageMax,
            'indoor_outdoor' => $this->detectIndoorOutdoor($text),
            'category' => $this->detectCategory($text),
            'language' => $this->detectLanguage($text),
            'short_summary' => $this->buildSummary($input),
        ];
    }

    private function extractAgeRange(string $text): array
    {
        if (preg_match('~\b(\d{1,2})\s*-\s*(\d{1,2})\s*(let|roku|years)?\b~u', $text, $m)) {
            return [(int) $m[1], (int) $m[2]];
        }

        if (preg_match('~\b(\d{1,2})\s*\+\s*(let|roku|years)?\b~u', $text, $m)) {
            return [(int) $m[1], null];
        }

        if (preg_match('~\bod\s*(\d{1,2})\s*(let|roku)\b~u', $text, $m)) {
            return [(int) $m[1], null];
        }

        if (preg_match('~\bpro děti\s*(\d{1,2})\s*-\s*(\d{1,2})\b~u', $text, $m)) {
            return [(int) $m[1], (int) $m[2]];
        }

        return [null, null];
    }

    private function detectKidFriendly(string $text, ?int $ageMin, ?int $ageMax): ?bool
    {
        if ($ageMin !== null || $ageMax !== null) {
            return true;
        }

        $keywords = ['děti', 'dets', 'rodinn', 'family', 'kids', 'pohádk', 'loutk'];
        foreach ($keywords as $keyword) {
            if (str_contains($text, $keyword)) {
                return true;
            }
        }

        return null;
    }

    private function detectIndoorOutdoor(string $text): string
    {
        $indoor = $this->hasAny($text, ['divadl', 'kino', 'hala', 'vnitř', 'interiér', 'museum', 'muze']);
        $outdoor = $this->hasAny($text, ['venku', 'park', 'les', 'zahrad', 'venkovn', 'outdoor']);

        if ($indoor && $outdoor) {
            return 'both';
        }

        if ($indoor) {
            return 'indoor';
        }

        if ($outdoor) {
            return 'outdoor';
        }

        return 'unknown';
    }

    private function detectCategory(string $text): string
    {
        $map = [
            'theatre' => ['divadl', 'loutk', 'představ'],
            'music' => ['koncert', 'hudb', 'kapel', 'zpěv'],
            'festival' => ['festival', 'fest'],
            'workshop' => ['díln', 'workshop', 'tvořiv', 'kreativ'],
            'education' => ['přednáš', 'eduk', 'vzděl'],
            'sports' => ['sport', 'běh', 'turnaj', 'závod'],
            'nature' => ['přírod', 'les', 'zoo', 'zvířat'],
            'exhibition' => ['výstav', 'expoz'],
        ];

        foreach ($map as $category => $keywords) {
            if ($this->hasAny($text, $keywords)) {
                return $category;
            }
        }

        return 'other';
    }

    private function detectLanguage(string $text): string
    {
        $hasCzech = (bool) preg_match('~[áéěíóúůýřžščďťň]~u', $text);
        $hasEnglish = $this->hasAny($text, ['english', 'workshop', 'kids', 'family']);

        if ($hasCzech && $hasEnglish) {
            return 'mixed';
        }

        if ($hasCzech) {
            return 'cs';
        }

        if ($hasEnglish) {
            return 'en';
        }

        return 'unknown';
    }

    private function buildSummary(array $input): ?string
    {
        $source = trim((string) ($input['description_raw'] ?? ''));
        if ($source === '') {
            return $this->trimSummary((string) ($input['title'] ?? ''));
        }

        $summary = preg_replace('/\s+/', ' ', $source);

        return $this->trimSummary($summary);
    }

    private function trimSummary(string $text): ?string
    {
        $text = trim($text);
        if ($text === '') {
            return null;
        }

        if (mb_strlen($text) > 200) {
            $text = mb_substr($text, 0, 197).'...';
        }

        return $text;
    }

    private function normalizeText(string $text): string
    {
        $text = mb_strtolower($text);
        $text = preg_replace('/\s+/', ' ', $text);

        return $text;
    }

    private function hasAny(string $text, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($text, $needle)) {
                return true;
            }
        }

        return false;
    }
}
