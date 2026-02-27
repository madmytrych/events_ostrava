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
        $fingerprintMatch = Event::query()
            ->where('fingerprint', $data->fingerprint)
            ->orderBy('id')
            ->first();

        if ($fingerprintMatch) {
            return $fingerprintMatch;
        }

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
