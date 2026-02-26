<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Bot;

use App\Models\Event;
use App\Services\Bot\EventQueryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class EventQueryServiceTest extends TestCase
{
    use RefreshDatabase;

    private EventQueryService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new EventQueryService();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function createEvent(array $overrides = []): Event
    {
        $defaults = [
            'source' => 'test',
            'source_url' => 'https://example.com/event/' . uniqid(),
            'source_event_id' => (string) random_int(10000, 99999),
            'title' => 'Test Event',
            'start_at' => Carbon::parse('2026-03-15 10:00:00', 'Europe/Prague'),
            'venue' => 'Venue',
            'location_name' => 'Venue',
            'fingerprint' => sha1(uniqid('', true)),
            'status' => 'new',
            'is_active' => true,
        ];

        return Event::forceCreate(array_merge($defaults, $overrides));
    }

    // --- getTodayEvents ---

    public function test_get_today_events_returns_events_within_today(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-15 12:00:00', 'Europe/Prague'));

        $todayEvent = $this->createEvent([
            'start_at' => Carbon::parse('2026-03-15 14:00:00', 'Europe/Prague'),
        ]);
        $this->createEvent([
            'start_at' => Carbon::parse('2026-03-16 10:00:00', 'Europe/Prague'),
        ]);

        $events = $this->service->getTodayEvents(null, null);

        $this->assertCount(1, $events);
        $this->assertSame($todayEvent->id, $events->first()->id);
    }

    // --- getTomorrowEvents ---

    public function test_get_tomorrow_events_returns_correct_events(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-15 12:00:00', 'Europe/Prague'));

        $this->createEvent([
            'start_at' => Carbon::parse('2026-03-15 14:00:00', 'Europe/Prague'),
        ]);
        $tomorrowEvent = $this->createEvent([
            'start_at' => Carbon::parse('2026-03-16 10:00:00', 'Europe/Prague'),
        ]);

        $events = $this->service->getTomorrowEvents(null, null);

        $this->assertCount(1, $events);
        $this->assertSame($tomorrowEvent->id, $events->first()->id);
    }

    // --- getWeekEvents ---

    public function test_get_week_events_returns_current_week(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-11 12:00:00', 'Europe/Prague'));

        $weekEvent = $this->createEvent([
            'start_at' => Carbon::parse('2026-03-13 10:00:00', 'Europe/Prague'),
        ]);
        $this->createEvent([
            'start_at' => Carbon::parse('2026-03-23 10:00:00', 'Europe/Prague'),
        ]);

        $events = $this->service->getWeekEvents(null, null);

        $this->assertCount(1, $events);
        $this->assertSame($weekEvent->id, $events->first()->id);
    }

    // --- getWeekendEvents ---

    public function test_get_weekend_events_returns_saturday_and_sunday(): void
    {
        // Wednesday 2026-03-11
        Carbon::setTestNow(Carbon::parse('2026-03-11 12:00:00', 'Europe/Prague'));

        $satEvent = $this->createEvent([
            'start_at' => Carbon::parse('2026-03-14 10:00:00', 'Europe/Prague'),
        ]);
        $sunEvent = $this->createEvent([
            'start_at' => Carbon::parse('2026-03-15 14:00:00', 'Europe/Prague'),
        ]);
        $this->createEvent([
            'start_at' => Carbon::parse('2026-03-16 10:00:00', 'Europe/Prague'),
        ]);

        $events = $this->service->getWeekendEvents(null, null);

        $this->assertCount(2, $events);
        $ids = $events->pluck('id')->toArray();
        $this->assertContains($satEvent->id, $ids);
        $this->assertContains($sunEvent->id, $ids);
    }

    public function test_get_weekend_events_when_today_is_saturday(): void
    {
        // Saturday 2026-03-14
        Carbon::setTestNow(Carbon::parse('2026-03-14 09:00:00', 'Europe/Prague'));

        $satEvent = $this->createEvent([
            'start_at' => Carbon::parse('2026-03-14 14:00:00', 'Europe/Prague'),
        ]);
        $sunEvent = $this->createEvent([
            'start_at' => Carbon::parse('2026-03-15 10:00:00', 'Europe/Prague'),
        ]);

        $events = $this->service->getWeekendEvents(null, null);

        $this->assertCount(2, $events);
    }

    public function test_get_weekend_events_when_today_is_sunday(): void
    {
        // Sunday 2026-03-15
        Carbon::setTestNow(Carbon::parse('2026-03-15 09:00:00', 'Europe/Prague'));

        $sunEvent = $this->createEvent([
            'start_at' => Carbon::parse('2026-03-15 14:00:00', 'Europe/Prague'),
        ]);
        $this->createEvent([
            'start_at' => Carbon::parse('2026-03-14 10:00:00', 'Europe/Prague'),
        ]);

        $events = $this->service->getWeekendEvents(null, null);

        $this->assertCount(1, $events);
        $this->assertSame($sunEvent->id, $events->first()->id);
    }

    // --- Filtering ---

    public function test_excludes_rejected_events(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-15 08:00:00', 'Europe/Prague'));

        $this->createEvent([
            'start_at' => Carbon::parse('2026-03-15 14:00:00', 'Europe/Prague'),
            'status' => 'rejected',
        ]);
        $approved = $this->createEvent([
            'start_at' => Carbon::parse('2026-03-15 15:00:00', 'Europe/Prague'),
            'status' => 'approved',
        ]);

        $events = $this->service->getTodayEvents(null, null);

        $this->assertCount(1, $events);
        $this->assertSame($approved->id, $events->first()->id);
    }

    public function test_excludes_inactive_events(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-15 08:00:00', 'Europe/Prague'));

        $this->createEvent([
            'start_at' => Carbon::parse('2026-03-15 14:00:00', 'Europe/Prague'),
            'is_active' => false,
        ]);
        $active = $this->createEvent([
            'start_at' => Carbon::parse('2026-03-15 15:00:00', 'Europe/Prague'),
            'is_active' => true,
        ]);

        $events = $this->service->getTodayEvents(null, null);

        $this->assertCount(1, $events);
        $this->assertSame($active->id, $events->first()->id);
    }

    public function test_excludes_duplicate_events(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-15 08:00:00', 'Europe/Prague'));

        $canonical = $this->createEvent([
            'start_at' => Carbon::parse('2026-03-15 14:00:00', 'Europe/Prague'),
        ]);
        $this->createEvent([
            'start_at' => Carbon::parse('2026-03-15 15:00:00', 'Europe/Prague'),
            'duplicate_of_event_id' => $canonical->id,
        ]);

        $events = $this->service->getTodayEvents(null, null);

        $this->assertCount(1, $events);
        $this->assertSame($canonical->id, $events->first()->id);
    }

    // --- Age filtering ---

    public function test_age_filter_shows_matching_events(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-15 08:00:00', 'Europe/Prague'));

        $matching = $this->createEvent([
            'start_at' => Carbon::parse('2026-03-15 14:00:00', 'Europe/Prague'),
            'age_min' => 3,
            'age_max' => 6,
        ]);
        $this->createEvent([
            'start_at' => Carbon::parse('2026-03-15 15:00:00', 'Europe/Prague'),
            'age_min' => 10,
            'age_max' => 15,
        ]);

        $events = $this->service->getTodayEvents(3, 6);

        $this->assertCount(1, $events);
        $this->assertSame($matching->id, $events->first()->id);
    }

    public function test_age_filter_includes_events_with_null_age(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-15 08:00:00', 'Europe/Prague'));

        $noAge = $this->createEvent([
            'start_at' => Carbon::parse('2026-03-15 14:00:00', 'Europe/Prague'),
            'age_min' => null,
            'age_max' => null,
        ]);

        $events = $this->service->getTodayEvents(3, 6);

        $this->assertCount(1, $events);
        $this->assertSame($noAge->id, $events->first()->id);
    }

    public function test_null_age_filter_returns_all_events(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-15 08:00:00', 'Europe/Prague'));

        $this->createEvent([
            'start_at' => Carbon::parse('2026-03-15 14:00:00', 'Europe/Prague'),
            'age_min' => 3,
            'age_max' => 6,
        ]);
        $this->createEvent([
            'start_at' => Carbon::parse('2026-03-15 15:00:00', 'Europe/Prague'),
            'age_min' => 10,
            'age_max' => 15,
        ]);

        $events = $this->service->getTodayEvents(null, null);

        $this->assertCount(2, $events);
    }

    public function test_age_filter_excludes_non_overlapping_range(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-15 08:00:00', 'Europe/Prague'));

        $this->createEvent([
            'start_at' => Carbon::parse('2026-03-15 14:00:00', 'Europe/Prague'),
            'age_min' => 10,
            'age_max' => 15,
        ]);

        $events = $this->service->getTodayEvents(0, 3);

        $this->assertCount(0, $events);
    }
}
