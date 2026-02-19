<?php
declare(strict_types=1);

namespace App\DTO;

use Illuminate\Support\Carbon;

final class EventData
{
    public function __construct(
        public string $source,
        public string $sourceUrl,
        public string $sourceEventId,
        public string $title,
        public Carbon $startAt,
        public ?Carbon $endAt,
        public ?string $venue,
        public ?string $locationName,
        public ?string $address,
        public ?string $priceText,
        public ?string $description,
        public ?string $descriptionRaw,
        public ?int $ageMin,
        public ?int $ageMax,
        public ?array $tags,
        public ?bool $kidFriendly,
        public string $fingerprint
    ) {
    }

    public function toArray(): array
    {
        return [
            'source' => $this->source,
            'source_url' => $this->sourceUrl,
            'source_event_id' => $this->sourceEventId,
            'title' => $this->title,
            'start_at' => $this->startAt,
            'end_at' => $this->endAt,
            'venue' => $this->venue,
            'location_name' => $this->locationName,
            'address' => $this->address,
            'price_text' => $this->priceText,
            'description' => $this->description,
            'description_raw' => $this->descriptionRaw,
            'age_min' => $this->ageMin,
            'age_max' => $this->ageMax,
            'tags' => $this->tags,
            'kid_friendly' => $this->kidFriendly,
            'fingerprint' => $this->fingerprint,
        ];
    }
}
