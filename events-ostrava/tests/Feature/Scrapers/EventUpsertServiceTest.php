<?php

declare(strict_types=1);

namespace Tests\Feature\Scrapers;

use App\DTO\EventData;
use App\Jobs\EnrichEventJob;
use App\Models\Event;
use App\Services\Scrapers\DuplicateResolver;
use App\Services\Scrapers\EventUpsertService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class EventUpsertServiceTest extends TestCase
{
    use RefreshDatabase;

    private EventUpsertService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new EventUpsertService(new DuplicateResolver());
    }

    private function makeEventData(array $overrides = []): EventData
    {
        $defaults = [
            'source' => 'test',
            'sourceUrl' => 'https://example.com/event/' . uniqid(),
            'sourceEventId' => (string) random_int(10000, 99999),
            'title' => 'Test Event',
            'startAt' => Carbon::parse('2026-03-15 10:00:00', 'Europe/Prague'),
            'endAt' => null,
            'venue' => 'Venue',
            'locationName' => 'Venue',
            'address' => null,
            'priceText' => null,
            'description' => 'A test event.',
            'descriptionRaw' => 'A test event.',
            'ageMin' => null,
            'ageMax' => null,
            'tags' => null,
            'kidFriendly' => null,
            'fingerprint' => '',
        ];

        $merged = array_merge($defaults, $overrides);

        return new EventData(...$merged);
    }

    public function test_creates_new_event_with_fingerprint(): void
    {
        Queue::fake();

        $data = $this->makeEventData();

        $result = $this->service->upsert($data);

        $this->assertTrue($result);
        $this->assertDatabaseCount('events', 1);

        $event = Event::first();
        $this->assertNotEmpty($event->fingerprint);
        $this->assertSame('new', $event->status);
        $this->assertSame('Test Event', $event->title);
    }

    public function test_dispatches_enrich_job_for_new_event(): void
    {
        Queue::fake();

        $data = $this->makeEventData();
        $this->service->upsert($data);

        Queue::assertPushed(EnrichEventJob::class);
    }

    public function test_updates_existing_event_when_data_changed(): void
    {
        Queue::fake();

        $data = $this->makeEventData([
            'sourceUrl' => 'https://example.com/event/fixed',
            'sourceEventId' => '12345',
        ]);
        $this->service->upsert($data);

        $updatedData = $this->makeEventData([
            'sourceUrl' => 'https://example.com/event/fixed',
            'sourceEventId' => '12345',
            'title' => 'Updated Title',
        ]);
        $result = $this->service->upsert($updatedData);

        $this->assertTrue($result);
        $this->assertDatabaseCount('events', 1);
        $this->assertSame('Updated Title', Event::first()->title);
    }

    public function test_skips_update_when_data_unchanged(): void
    {
        Queue::fake();

        $data = $this->makeEventData([
            'sourceUrl' => 'https://example.com/event/fixed',
            'sourceEventId' => '12345',
        ]);
        $this->service->upsert($data);

        $sameData = $this->makeEventData([
            'sourceUrl' => 'https://example.com/event/fixed',
            'sourceEventId' => '12345',
        ]);
        $result = $this->service->upsert($sameData);

        $this->assertFalse($result);
        $this->assertDatabaseCount('events', 1);
    }

    public function test_links_duplicate_and_does_not_dispatch_enrichment(): void
    {
        Queue::fake();

        $startAt = Carbon::parse('2026-03-15 10:00:00', 'Europe/Prague');
        $resolver = new DuplicateResolver();
        $fp = $resolver->fingerprint('Puppet Show', $startAt, 'Theatre');

        Event::forceCreate([
            'source' => 'source_a',
            'source_url' => 'https://a.com/1',
            'source_event_id' => '100',
            'title' => 'Puppet Show',
            'start_at' => $startAt,
            'venue' => 'Theatre',
            'location_name' => 'Theatre',
            'fingerprint' => $fp,
            'status' => 'new',
            'is_active' => true,
        ]);

        Queue::fake();

        $data = $this->makeEventData([
            'source' => 'source_b',
            'title' => 'Puppet Show',
            'startAt' => $startAt,
            'venue' => 'Theatre',
            'locationName' => 'Theatre',
        ]);

        $this->service->upsert($data);

        $this->assertDatabaseCount('events', 2);

        $duplicate = Event::where('source', 'source_b')->first();
        $this->assertNotNull($duplicate->duplicate_of_event_id);

        Queue::assertNotPushed(EnrichEventJob::class);
    }
}
