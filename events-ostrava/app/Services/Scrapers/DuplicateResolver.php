<?php

declare(strict_types=1);

namespace App\Services\Scrapers;

use App\DTO\EventData;
use App\Models\Event;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

final class DuplicateResolver
{
    public function fingerprint(string $title, Carbon $startAt, ?string $venue): string
    {
        $titleNormalized = mb_strtolower(trim($title));
        $venueNormalized = mb_strtolower(trim($venue ?? ''));
        $date = $startAt->format('Y-m-d H:i');

        return sha1($titleNormalized . '|' . $date . '|' . $venueNormalized);
    }

    public function findDuplicateCandidate(EventData $data): ?Event
    {
        // 1. Check by fingerprint (most reliable)
        $fingerprintMatch = Event::query()
            ->where('fingerprint', $data->fingerprint)
            ->orderBy('id')
            ->first();

        if ($fingerprintMatch) {
            return $fingerprintMatch;
        }

        // 2. Check by source_event_id across known related sources
        // visitostrava.eu and ostravainfo.cz share the same event IDs
        $relatedSources = $this->getRelatedSources($data->source);
        if (!empty($relatedSources)) {
            $sourceIdMatch = Event::query()
                ->whereIn('source', $relatedSources)
                ->where('source_event_id', $data->sourceEventId)
                ->orderBy('id')
                ->first();

            if ($sourceIdMatch) {
                return $sourceIdMatch;
            }
        }

        // 3. Check by URL pattern (extract ID from URL)
        if (preg_match('~/(\d+)-[^/]+\.html$~', $data->sourceUrl, $matches)) {
            $urlEventId = $matches[1];
            $urlMatch = Event::query()
                ->where('source_url', 'LIKE', "%/{$urlEventId}-%")
                ->where('source', '!=', $data->source)
                ->orderBy('id')
                ->first();

            if ($urlMatch) {
                return $urlMatch;
            }
        }

        // 4. Fuzzy matching by title + location (fallback for other sources)
        $title = $this->normalizeText($data->title);
        $location = $this->normalizeText($data->locationName ?? $data->venue ?? '');

        $candidates = Event::query()
            ->where('start_at', $data->startAt)
            ->where('status', '!=', 'rejected')
            ->get();

        $best = null;
        $bestScore = 0.0;

        foreach ($candidates as $candidate) {
            $candidateTitle = $this->normalizeText($candidate->title ?? '');
            if ($candidateTitle === '' || $title === '') {
                continue;
            }

            similar_text($title, $candidateTitle, $titleScore);

            $locationScore = 0.0;
            $candidateLocation = $this->normalizeText($candidate->location_name ?? $candidate->venue ?? '');
            if ($location !== '' && $candidateLocation !== '') {
                similar_text($location, $candidateLocation, $locationScore);
            }

            $isDuplicate = false;
            if ($location !== '' && $candidateLocation !== '') {
                $isDuplicate = $titleScore >= 80 && $locationScore >= 70;
            } else {
                $isDuplicate = $titleScore >= 90;
            }

            if ($isDuplicate && $titleScore > $bestScore) {
                $best = $candidate;
                $bestScore = $titleScore;
            }
        }

        return $best;
    }

    /**
     * Get sources that are known to share the same event IDs
     */
    private function getRelatedSources(string $source): array
    {
        $relatedGroups = [
            ['visitostrava', 'ostravainfo'],
        ];

        foreach ($relatedGroups as $group) {
            if (in_array($source, $group, true)) {
                return array_values(array_diff($group, [$source]));
            }
        }

        return [];
    }

    private const int MAX_DUPLICATE_CHAIN_DEPTH = 10;

    public function resolveDuplicateRootId(Event $event): int
    {
        $root = $event;
        $depth = 0;

        while ($root->duplicate_of_event_id) {
            if (++$depth > self::MAX_DUPLICATE_CHAIN_DEPTH) {
                Log::warning('Circular or excessively deep duplicate chain detected', [
                    'event_id' => $event->id,
                    'current_id' => $root->id,
                    'depth' => $depth,
                ]);

                return $root->id;
            }

            $parent = Event::query()->find($root->duplicate_of_event_id);
            if (!$parent) {
                break;
            }
            $root = $parent;
        }

        return $root->id;
    }

    private function normalizeText(string $text): string
    {
        $text = mb_strtolower(trim($text));
        $text = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $text);
        $text = preg_replace('/\s+/', ' ', $text);

        return trim($text);
    }
}
