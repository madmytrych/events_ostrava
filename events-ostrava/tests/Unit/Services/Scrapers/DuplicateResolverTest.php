<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Scrapers;

use App\DTO\EventData;
use App\Models\Event;
use App\Services\Scrapers\DuplicateResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DuplicateResolverTest extends TestCase
{
    use RefreshDatabase;

    private DuplicateResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new DuplicateResolver();
    }

    private function createEvent(array $overrides = []): Event
    {
        $defaults = [
            'source' => 'test',
            'source_url' => 'https://example.com/event/' . uniqid(),
            'source_event_id' => (string) random_int(10000, 99999),
            'title' => 'Test Event',
            'start_at' => Carbon::parse('2026-03-15 10:00:00', 'Europe/Prague'),
            'venue' => 'Test Venue',
            'location_name' => 'Test Venue',
            'fingerprint' => sha1(uniqid('', true)),
            'status' => 'new',
            'is_active' => true,
        ];

        return Event::forceCreate(array_merge($defaults, $overrides));
    }

    // --- fingerprint ---

    public function test_fingerprint_is_consistent(): void
    {
        $title = 'Puppet Show for Kids';
        $startAt = Carbon::parse('2026-03-15 10:00:00');
        $venue = 'Theatre Ostrava';

        $fp1 = $this->resolver->fingerprint($title, $startAt, $venue);
        $fp2 = $this->resolver->fingerprint($title, $startAt, $venue);

        $this->assertSame($fp1, $fp2);
    }

    public function test_fingerprint_normalizes_case(): void
    {
        $startAt = Carbon::parse('2026-03-15 10:00:00');

        $fp1 = $this->resolver->fingerprint('Puppet Show', $startAt, 'Theatre');
        $fp2 = $this->resolver->fingerprint('PUPPET SHOW', $startAt, 'THEATRE');

        $this->assertSame($fp1, $fp2);
    }

    public function test_fingerprint_normalizes_whitespace(): void
    {
        $startAt = Carbon::parse('2026-03-15 10:00:00');

        $fp1 = $this->resolver->fingerprint('Puppet Show', $startAt, 'Theatre');
        $fp2 = $this->resolver->fingerprint('  Puppet Show  ', $startAt, '  Theatre  ');

        $this->assertSame($fp1, $fp2);
    }

    public function test_fingerprint_handles_null_venue(): void
    {
        $startAt = Carbon::parse('2026-03-15 10:00:00');

        $fp = $this->resolver->fingerprint('Event', $startAt, null);

        $this->assertNotEmpty($fp);
        $this->assertSame(40, strlen($fp));
    }

    // --- findDuplicateCandidate ---

    public function test_find_duplicate_by_exact_fingerprint(): void
    {
        $fp = $this->resolver->fingerprint('Puppet Show', Carbon::parse('2026-03-15 10:00:00'), 'Theatre');

        $existing = $this->createEvent([
            'title' => 'Puppet Show',
            'fingerprint' => $fp,
            'start_at' => Carbon::parse('2026-03-15 10:00:00'),
        ]);

        $data = new EventData(
            source: 'other',
            sourceUrl: 'https://other.com/event/1',
            sourceEventId: '999',
            title: 'Puppet Show',
            startAt: Carbon::parse('2026-03-15 10:00:00'),
            endAt: null,
            venue: 'Theatre',
            locationName: 'Theatre',
            address: null,
            priceText: null,
            description: null,
            descriptionRaw: null,
            ageMin: null,
            ageMax: null,
            tags: null,
            kidFriendly: null,
            fingerprint: $fp
        );

        $result = $this->resolver->findDuplicateCandidate($data);

        $this->assertNotNull($result);
        $this->assertSame($existing->id, $result->id);
    }

    public function test_find_duplicate_by_fuzzy_title_and_location(): void
    {
        $existing = $this->createEvent([
            'title' => 'Puppet Show for Kids in Ostrava',
            'start_at' => Carbon::parse('2026-03-15 10:00:00'),
            'location_name' => 'Theatre Ostrava',
        ]);

        $data = new EventData(
            source: 'other',
            sourceUrl: 'https://other.com/event/2',
            sourceEventId: '888',
            title: 'Puppet Show for Kids in Ostrava!',
            startAt: Carbon::parse('2026-03-15 10:00:00'),
            endAt: null,
            venue: 'Theatre Ostrava',
            locationName: 'Theatre Ostrava',
            address: null,
            priceText: null,
            description: null,
            descriptionRaw: null,
            ageMin: null,
            ageMax: null,
            tags: null,
            kidFriendly: null,
            fingerprint: 'different-fingerprint'
        );

        $result = $this->resolver->findDuplicateCandidate($data);

        $this->assertNotNull($result);
        $this->assertSame($existing->id, $result->id);
    }

    public function test_find_duplicate_returns_null_when_below_threshold(): void
    {
        $this->createEvent([
            'title' => 'Concert in Park',
            'start_at' => Carbon::parse('2026-03-15 10:00:00'),
            'location_name' => 'City Park',
        ]);

        $data = new EventData(
            source: 'other',
            sourceUrl: 'https://other.com/event/3',
            sourceEventId: '777',
            title: 'Completely Different Event Title',
            startAt: Carbon::parse('2026-03-15 10:00:00'),
            endAt: null,
            venue: 'Some Other Place',
            locationName: 'Some Other Place',
            address: null,
            priceText: null,
            description: null,
            descriptionRaw: null,
            ageMin: null,
            ageMax: null,
            tags: null,
            kidFriendly: null,
            fingerprint: 'no-match'
        );

        $result = $this->resolver->findDuplicateCandidate($data);

        $this->assertNull($result);
    }

    public function test_find_duplicate_ignores_rejected_events(): void
    {
        $this->createEvent([
            'title' => 'Puppet Show for Kids in Ostrava',
            'start_at' => Carbon::parse('2026-03-15 10:00:00'),
            'location_name' => 'Theatre Ostrava',
            'status' => 'rejected',
        ]);

        $data = new EventData(
            source: 'other',
            sourceUrl: 'https://other.com/event/4',
            sourceEventId: '666',
            title: 'Puppet Show for Kids in Ostrava',
            startAt: Carbon::parse('2026-03-15 10:00:00'),
            endAt: null,
            venue: 'Theatre Ostrava',
            locationName: 'Theatre Ostrava',
            address: null,
            priceText: null,
            description: null,
            descriptionRaw: null,
            ageMin: null,
            ageMax: null,
            tags: null,
            kidFriendly: null,
            fingerprint: 'no-match'
        );

        $result = $this->resolver->findDuplicateCandidate($data);

        $this->assertNull($result);
    }

    // --- resolveDuplicateRootId ---

    public function test_resolve_duplicate_root_follows_chain(): void
    {
        $root = $this->createEvent(['title' => 'Root Event']);
        $child = $this->createEvent([
            'title' => 'Child Event',
            'duplicate_of_event_id' => $root->id,
        ]);
        $grandchild = $this->createEvent([
            'title' => 'Grandchild Event',
            'duplicate_of_event_id' => $child->id,
        ]);

        $result = $this->resolver->resolveDuplicateRootId($grandchild);

        $this->assertSame($root->id, $result);
    }

    public function test_resolve_duplicate_root_handles_missing_parent(): void
    {
        $event = $this->createEvent([
            'title' => 'Orphan Event',
            'duplicate_of_event_id' => 99999,
        ]);

        $result = $this->resolver->resolveDuplicateRootId($event);

        $this->assertSame($event->id, $result);
    }

    public function test_resolve_duplicate_root_returns_self_for_non_duplicate(): void
    {
        $event = $this->createEvent(['title' => 'Standalone Event']);

        $result = $this->resolver->resolveDuplicateRootId($event);

        $this->assertSame($event->id, $result);
    }

    public function test_resolve_duplicate_root_handles_circular_reference(): void
    {
        $a = $this->createEvent(['title' => 'Event A']);
        $b = $this->createEvent(['title' => 'Event B', 'duplicate_of_event_id' => $a->id]);

        $a->update(['duplicate_of_event_id' => $b->id]);

        $result = $this->resolver->resolveDuplicateRootId($a);

        $this->assertContains($result, [$a->id, $b->id]);
    }
}
