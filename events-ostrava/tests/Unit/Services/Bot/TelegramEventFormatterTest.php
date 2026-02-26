<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Bot;

use App\Models\Event;
use App\Services\Bot\TelegramEventFormatter;
use App\Services\Bot\TelegramTextService;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class TelegramEventFormatterTest extends TestCase
{
    private TelegramEventFormatter $formatter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->formatter = new TelegramEventFormatter(new TelegramTextService());
    }

    private function makeEvent(array $attrs = []): Event
    {
        $defaults = [
            'title' => 'Puppet Show',
            'short_summary' => 'A fun puppet show for kids.',
            'summary' => 'A longer summary of the puppet show.',
            'start_at' => Carbon::parse('2026-03-15 10:00:00', 'Europe/Prague'),
            'end_at' => null,
            'location_name' => 'Theatre Ostrava',
            'venue' => null,
            'address' => 'Main Street 1',
            'indoor_outdoor' => 'indoor',
            'age_min' => 3,
            'age_max' => 6,
            'source_url' => 'https://example.com/event/1',
            'title_i18n' => null,
            'summary_i18n' => null,
            'short_summary_i18n' => null,
        ];

        $event = new Event();
        foreach (array_merge($defaults, $attrs) as $key => $value) {
            $event->setAttribute($key, $value);
        }

        return $event;
    }

    public function test_format_event_renders_all_card_fields(): void
    {
        $event = $this->makeEvent();
        $output = $this->formatter->formatEvent($event, 'en');

        $this->assertStringContainsString('Puppet Show', $output);
        $this->assertStringContainsString('3–6', $output);
        $this->assertStringContainsString('Theatre Ostrava', $output);
        $this->assertStringContainsString('Indoor', $output);
        $this->assertStringContainsString('A fun puppet show for kids.', $output);
        $this->assertStringContainsString('https://example.com/event/1', $output);
    }

    public function test_format_event_uses_i18n_fields_when_present(): void
    {
        $event = $this->makeEvent([
            'title_i18n' => ['uk' => 'Лялькова вистава'],
            'short_summary_i18n' => ['uk' => 'Весела лялькова вистава.'],
        ]);

        $output = $this->formatter->formatEvent($event, 'uk');

        $this->assertStringContainsString('Лялькова вистава', $output);
        $this->assertStringContainsString('Весела лялькова вистава.', $output);
    }

    public function test_format_event_falls_back_to_base_fields(): void
    {
        $event = $this->makeEvent([
            'title_i18n' => ['cs' => 'Loutkové divadlo'],
        ]);

        $output = $this->formatter->formatEvent($event, 'en');

        $this->assertStringContainsString('Puppet Show', $output);
    }

    // --- Age formatting ---

    public function test_format_age_all_ages(): void
    {
        $event = $this->makeEvent(['age_min' => null, 'age_max' => null]);
        $output = $this->formatter->formatEvent($event, 'en');

        $this->assertStringContainsString('All ages', $output);
    }

    public function test_format_age_range(): void
    {
        $event = $this->makeEvent(['age_min' => 3, 'age_max' => 6]);
        $output = $this->formatter->formatEvent($event, 'en');

        $this->assertStringContainsString('3–6', $output);
    }

    public function test_format_age_from(): void
    {
        $event = $this->makeEvent(['age_min' => 5, 'age_max' => null]);
        $output = $this->formatter->formatEvent($event, 'en');

        $this->assertStringContainsString('from 5', $output);
    }

    public function test_format_age_to(): void
    {
        $event = $this->makeEvent(['age_min' => null, 'age_max' => 10]);
        $output = $this->formatter->formatEvent($event, 'en');

        $this->assertStringContainsString('up to 10', $output);
    }

    // --- Time formatting ---

    public function test_format_time_single_day(): void
    {
        $event = $this->makeEvent([
            'start_at' => Carbon::parse('2026-03-15 09:00:00', 'UTC'),
            'end_at' => Carbon::parse('2026-03-15 11:00:00', 'UTC'),
        ]);

        $output = $this->formatter->formatEvent($event, 'en');

        $this->assertStringContainsString('10:00', $output);
        $this->assertStringContainsString('12:00', $output);
    }

    public function test_format_time_no_end(): void
    {
        $event = $this->makeEvent([
            'start_at' => Carbon::parse('2026-03-15 09:00:00', 'UTC'),
            'end_at' => null,
        ]);

        $output = $this->formatter->formatEvent($event, 'en');

        $this->assertStringContainsString('10:00', $output);
    }

    // --- Indoor/Outdoor ---

    public function test_format_indoor(): void
    {
        $event = $this->makeEvent(['indoor_outdoor' => 'indoor']);
        $output = $this->formatter->formatEvent($event, 'en');

        $this->assertStringContainsString('Indoor', $output);
    }

    public function test_format_outdoor(): void
    {
        $event = $this->makeEvent(['indoor_outdoor' => 'outdoor']);
        $output = $this->formatter->formatEvent($event, 'en');

        $this->assertStringContainsString('Outdoor', $output);
    }

    public function test_format_both_indoor_outdoor(): void
    {
        $event = $this->makeEvent(['indoor_outdoor' => 'both']);
        $output = $this->formatter->formatEvent($event, 'en');

        $this->assertStringContainsString('Indoor / Outdoor', $output);
    }

    // --- formatEventsResponse ---

    public function test_format_events_response_empty(): void
    {
        $events = collect();
        $response = $this->formatter->formatEventsResponse('label_today', $events, 'en');

        $this->assertStringContainsString('No events found', $response['text']);
    }

    public function test_format_events_response_with_events(): void
    {
        $events = collect([
            $this->makeEvent(['title' => 'Event A']),
            $this->makeEvent(['title' => 'Event B']),
        ]);

        $response = $this->formatter->formatEventsResponse('label_today', $events, 'en');

        $this->assertStringContainsString('Event A', $response['text']);
        $this->assertStringContainsString('Event B', $response['text']);
    }

    // --- Summary fallback ---

    public function test_summary_falls_back_when_short_summary_missing(): void
    {
        $event = $this->makeEvent([
            'short_summary' => null,
            'summary' => 'The longer summary text.',
        ]);

        $output = $this->formatter->formatEvent($event, 'en');

        $this->assertStringContainsString('The longer summary text.', $output);
    }

    public function test_summary_fallback_message_when_both_missing(): void
    {
        $event = $this->makeEvent([
            'short_summary' => null,
            'summary' => null,
        ]);

        $output = $this->formatter->formatEvent($event, 'en');

        $this->assertStringContainsString('Short description is not available yet.', $output);
    }
}
