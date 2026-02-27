<?php

declare(strict_types=1);

namespace App\Services\Scrapers;

use App\DTO\EventData;
use App\Jobs\EnrichEventJob;
use App\Models\Event;

final readonly class EventUpsertService
{
    public function __construct(private DuplicateResolver $duplicateResolver) {}

    public function upsert(EventData $data): bool
    {
        // Calculate fingerprint first (before any DB operations)
        if ($data->fingerprint === '') {
            $data->fingerprint = $this->duplicateResolver->fingerprint(
                $data->title,
                $data->startAt,
                $data->venue
            );
        }

        // Check if this exact event from this source already exists
        $event = (new Event)->where('source', $data->source)
            ->where('source_event_id', $data->sourceEventId)
            ->first();

        if ($event) {
            // Update existing event from same source
            $event->fill($data->toArray());
            if ($event->isDirty()) {
                $event->save();

                return true;
            }

            return false;
        }

        // Check for duplicate by fingerprint BEFORE creating new event
        // This prevents enriching duplicates (saves money)
        $existingByFingerprint = Event::query()
            ->where('fingerprint', $data->fingerprint)
            ->whereNull('duplicate_of_event_id') // Only match root events
            ->orderBy('id')
            ->first();

        if ($existingByFingerprint) {
            // Found exact fingerprint match - create as duplicate without enriching
            $eventData = $data->toArray();
            $eventData['duplicate_of_event_id'] = $existingByFingerprint->id;
            $eventData['status'] = 'new';
            (new Event)->create($eventData);

            return true;
        }

        // Fallback to fuzzy matching (similar_text algorithm)
        $duplicate = $this->duplicateResolver->findDuplicateCandidate($data);
        if ($duplicate) {
            $eventData = $data->toArray();
            $eventData['duplicate_of_event_id'] = $this->duplicateResolver->resolveDuplicateRootId($duplicate);
        } else {
            $eventData = $data->toArray();
        }

        $eventData['status'] = 'new';
        $created = (new Event)->create($eventData);

        // Only enrich if it's not a duplicate (saves money)
        if (!isset($eventData['duplicate_of_event_id'])) {
            EnrichEventJob::dispatch($created->id);
        }

        return true;
    }
}
