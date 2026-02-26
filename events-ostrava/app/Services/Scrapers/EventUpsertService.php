<?php

declare(strict_types=1);

namespace App\Services\Scrapers;

use App\DTO\EventData;
use App\Jobs\EnrichEventJob;
use App\Models\Event;

final class EventUpsertService
{
    public function __construct(private readonly DuplicateResolver $duplicateResolver) {}

    public function upsert(EventData $data): bool
    {
        if ($data->fingerprint === '') {
            $data->fingerprint = $this->duplicateResolver->fingerprint(
                $data->title,
                $data->startAt,
                $data->venue
            );
        }

        $event = Event::where('source', $data->source)
            ->where('source_event_id', $data->sourceEventId)
            ->first();

        if ($event) {
            $event->fill($data->toArray());
            if ($event->isDirty()) {
                $event->save();

                return true;
            }

            return false;
        }

        $duplicate = $this->duplicateResolver->findDuplicateCandidate($data);
        if ($duplicate) {
            $eventData = $data->toArray();
            $eventData['duplicate_of_event_id'] = $this->duplicateResolver->resolveDuplicateRootId($duplicate);
        } else {
            $eventData = $data->toArray();
        }

        $eventData['status'] = 'new';
        $created = Event::create($eventData);

        if (! isset($eventData['duplicate_of_event_id'])) {
            EnrichEventJob::dispatch($created->id);
        }

        return true;
    }
}
