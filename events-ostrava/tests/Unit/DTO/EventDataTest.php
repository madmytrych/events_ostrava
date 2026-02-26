<?php

declare(strict_types=1);

namespace Tests\Unit\DTO;

use App\DTO\EventData;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

class EventDataTest extends TestCase
{
    public function test_to_array_returns_all_fields_with_correct_keys(): void
    {
        $startAt = Carbon::parse('2026-03-15 10:00:00');

        $data = new EventData(
            source: 'visitostrava',
            sourceUrl: 'https://example.com/event/1',
            sourceEventId: '12345',
            title: 'Test Event',
            startAt: $startAt,
            endAt: null,
            venue: 'Theatre',
            locationName: 'Theatre Ostrava',
            address: 'Main Street 1',
            priceText: '100 CZK',
            description: 'A great event.',
            descriptionRaw: '<p>A great event.</p>',
            ageMin: 3,
            ageMax: 10,
            tags: ['family', 'kids'],
            kidFriendly: true,
            fingerprint: 'abc123'
        );

        $array = $data->toArray();

        $this->assertSame('visitostrava', $array['source']);
        $this->assertSame('https://example.com/event/1', $array['source_url']);
        $this->assertSame('12345', $array['source_event_id']);
        $this->assertSame('Test Event', $array['title']);
        $this->assertSame($startAt, $array['start_at']);
        $this->assertNull($array['end_at']);
        $this->assertSame('Theatre', $array['venue']);
        $this->assertSame('Theatre Ostrava', $array['location_name']);
        $this->assertSame('Main Street 1', $array['address']);
        $this->assertSame('100 CZK', $array['price_text']);
        $this->assertSame('A great event.', $array['description']);
        $this->assertSame('<p>A great event.</p>', $array['description_raw']);
        $this->assertSame(3, $array['age_min']);
        $this->assertSame(10, $array['age_max']);
        $this->assertSame(['family', 'kids'], $array['tags']);
        $this->assertTrue($array['kid_friendly']);
        $this->assertSame('abc123', $array['fingerprint']);
    }

    public function test_to_array_contains_exactly_expected_keys(): void
    {
        $data = new EventData(
            source: 'test',
            sourceUrl: 'https://example.com',
            sourceEventId: '1',
            title: 'T',
            startAt: Carbon::now(),
            endAt: null,
            venue: null,
            locationName: null,
            address: null,
            priceText: null,
            description: null,
            descriptionRaw: null,
            ageMin: null,
            ageMax: null,
            tags: null,
            kidFriendly: null,
            fingerprint: ''
        );

        $expectedKeys = [
            'source', 'source_url', 'source_event_id', 'title',
            'start_at', 'end_at', 'venue', 'location_name', 'address',
            'price_text', 'description', 'description_raw',
            'age_min', 'age_max', 'tags', 'kid_friendly', 'fingerprint',
        ];

        $this->assertSame($expectedKeys, array_keys($data->toArray()));
    }
}
