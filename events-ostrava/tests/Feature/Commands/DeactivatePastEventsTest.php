<?php

declare(strict_types=1);

namespace Tests\Feature\Commands;

use App\Models\Event;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DeactivatePastEventsTest extends TestCase
{
    use RefreshDatabase;

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
            'start_at' => Carbon::parse('2026-03-15 10:00:00'),
            'venue' => 'Venue',
            'location_name' => 'Venue',
            'fingerprint' => sha1(uniqid('', true)),
            'status' => 'new',
            'is_active' => true,
        ];

        return Event::forceCreate(array_merge($defaults, $overrides));
    }

    public function test_deactivates_events_past_end_at(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-15 14:00:00', 'Europe/Prague'));

        $pastEvent = $this->createEvent([
            'start_at' => Carbon::parse('2026-03-15 10:00:00', 'Europe/Prague'),
            'end_at' => Carbon::parse('2026-03-15 12:00:00', 'Europe/Prague'),
        ]);

        $this->artisan('events:deactivate-past')
            ->assertExitCode(0);

        $pastEvent->refresh();
        $this->assertFalse($pastEvent->is_active);
    }

    public function test_deactivates_events_past_start_at_when_no_end_at(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-15 14:00:00', 'Europe/Prague'));

        $pastEvent = $this->createEvent([
            'start_at' => Carbon::parse('2026-03-15 12:00:00', 'Europe/Prague'),
            'end_at' => null,
        ]);

        $this->artisan('events:deactivate-past')
            ->assertExitCode(0);

        $pastEvent->refresh();
        $this->assertFalse($pastEvent->is_active);
    }

    public function test_does_not_deactivate_future_events(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-15 10:00:00', 'Europe/Prague'));

        $futureEvent = $this->createEvent([
            'start_at' => Carbon::parse('2026-03-16 10:00:00', 'Europe/Prague'),
            'end_at' => null,
        ]);

        $this->artisan('events:deactivate-past')
            ->assertExitCode(0);

        $futureEvent->refresh();
        $this->assertTrue($futureEvent->is_active);
    }

    public function test_respects_grace_hours(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-15 13:00:00', 'Europe/Prague'));

        $recentEvent = $this->createEvent([
            'start_at' => Carbon::parse('2026-03-15 12:00:00', 'Europe/Prague'),
            'end_at' => null,
        ]);

        $this->artisan('events:deactivate-past', ['--grace-hours' => 2])
            ->assertExitCode(0);

        $recentEvent->refresh();
        $this->assertTrue($recentEvent->is_active);
    }

    public function test_grace_hours_deactivates_old_enough_events(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-15 16:00:00', 'Europe/Prague'));

        $oldEvent = $this->createEvent([
            'start_at' => Carbon::parse('2026-03-15 10:00:00', 'Europe/Prague'),
            'end_at' => null,
        ]);

        $this->artisan('events:deactivate-past', ['--grace-hours' => 2])
            ->assertExitCode(0);

        $oldEvent->refresh();
        $this->assertFalse($oldEvent->is_active);
    }

    public function test_does_not_touch_already_inactive_events(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-15 14:00:00', 'Europe/Prague'));

        $inactiveEvent = $this->createEvent([
            'start_at' => Carbon::parse('2026-03-15 10:00:00', 'Europe/Prague'),
            'is_active' => false,
        ]);

        $this->artisan('events:deactivate-past')
            ->assertExitCode(0)
            ->expectsOutputToContain('Deactivated 0');
    }
}
