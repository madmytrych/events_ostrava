<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\DTO\EnrichmentResult;
use App\Jobs\EnrichEventJob;
use App\Models\Event;
use App\Services\Enrichment\EnrichmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class EnrichEventJobTest extends TestCase
{
    use RefreshDatabase;

    private function createEvent(array $overrides = []): Event
    {
        $defaults = [
            'source' => 'test',
            'source_url' => 'https://example.com/event/' . uniqid(),
            'source_event_id' => (string) random_int(10000, 99999),
            'title' => 'Test Event',
            'description_raw' => 'A test event description.',
            'start_at' => Carbon::parse('2026-03-15 10:00:00'),
            'venue' => 'Venue',
            'location_name' => 'Venue',
            'fingerprint' => sha1(uniqid('', true)),
            'status' => 'new',
            'is_active' => true,
        ];

        return Event::forceCreate(array_merge($defaults, $overrides));
    }

    private function fakeEnrichmentService(?EnrichmentResult $result = null, ?\Throwable $exception = null): EnrichmentService
    {
        $defaultResult = new EnrichmentResult(
            logId: 1,
            fields: [
                'short_summary' => 'Enriched summary.',
                'kid_friendly' => true,
                'age_min' => 3,
                'age_max' => 6,
                'indoor_outdoor' => 'indoor',
                'category' => 'theatre',
                'language' => 'cs',
            ],
            mode: 'ai'
        );

        $fakeAi = new class ($result ?? $defaultResult, $exception) implements \App\Services\Enrichment\Contracts\EnrichmentProviderInterface {
            public function __construct(
                private readonly EnrichmentResult $result,
                private readonly ?\Throwable $exception,
            ) {}

            public function enrich(Event $event, string $reason = 'rules'): EnrichmentResult
            {
                if ($this->exception) {
                    throw $this->exception;
                }

                return $this->result;
            }
        };

        $fakeRules = new class implements \App\Services\Enrichment\Contracts\EnrichmentProviderInterface {
            public function enrich(Event $event, string $reason = 'rules'): EnrichmentResult
            {
                return new EnrichmentResult(logId: 99, fields: ['short_summary' => 'Rules.'], mode: 'rules');
            }
        };

        $service = new EnrichmentService($fakeAi, $fakeRules);
        $this->app->instance(EnrichmentService::class, $service);

        return $service;
    }

    public function test_skips_missing_event(): void
    {
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(fn ($msg) => str_contains($msg, 'event not found'));
        Log::shouldReceive('info')->zeroOrMoreTimes();

        $service = $this->fakeEnrichmentService();
        $job = new EnrichEventJob(99999);
        $job->handle($service);
    }

    public function test_skips_duplicate_event(): void
    {
        $canonical = $this->createEvent();
        $duplicate = $this->createEvent([
            'duplicate_of_event_id' => $canonical->id,
        ]);

        Log::shouldReceive('info')
            ->once()
            ->withArgs(fn ($msg) => str_contains($msg, 'duplicate event skipped'));
        Log::shouldReceive('warning')->zeroOrMoreTimes();

        $service = $this->fakeEnrichmentService();
        $job = new EnrichEventJob($duplicate->id);
        $job->handle($service);
    }

    public function test_skips_already_enriched_event(): void
    {
        $event = $this->createEvent([
            'short_summary' => 'Already has summary.',
            'enriched_at' => Carbon::now(),
        ]);

        Log::shouldReceive('info')
            ->once()
            ->withArgs(fn ($msg) => str_contains($msg, 'already enriched'));
        Log::shouldReceive('warning')->zeroOrMoreTimes();

        $service = $this->fakeEnrichmentService();
        $job = new EnrichEventJob($event->id);
        $job->handle($service);
    }

    public function test_increments_enrichment_attempts(): void
    {
        $event = $this->createEvent(['enrichment_attempts' => 0]);
        $service = $this->fakeEnrichmentService();

        $job = new EnrichEventJob($event->id);
        $job->handle($service);

        $event->refresh();
        $this->assertSame(1, $event->enrichment_attempts);
    }

    public function test_saves_enrichment_result_fields(): void
    {
        $event = $this->createEvent();
        $service = $this->fakeEnrichmentService(new EnrichmentResult(
            logId: 42,
            fields: [
                'short_summary' => 'AI generated summary.',
                'kid_friendly' => true,
                'age_min' => 3,
                'age_max' => 10,
                'indoor_outdoor' => 'indoor',
                'category' => 'theatre',
                'language' => 'cs',
            ],
            mode: 'ai'
        ));

        $job = new EnrichEventJob($event->id);
        $job->handle($service);

        $event->refresh();
        $this->assertSame('AI generated summary.', $event->short_summary);
        $this->assertTrue($event->kid_friendly);
        $this->assertSame(42, $event->enrichment_log_id);
        $this->assertNotNull($event->enriched_at);
        $this->assertFalse($event->needs_review);
    }

    public function test_copies_short_summary_to_summary_when_empty(): void
    {
        $event = $this->createEvent(['summary' => null]);
        $service = $this->fakeEnrichmentService(new EnrichmentResult(
            logId: 1,
            fields: ['short_summary' => 'Generated summary.'],
            mode: 'ai'
        ));

        $job = new EnrichEventJob($event->id);
        $job->handle($service);

        $event->refresh();
        $this->assertSame('Generated summary.', $event->summary);
    }

    public function test_sets_needs_review_for_rules_mode(): void
    {
        $event = $this->createEvent();
        $service = $this->fakeEnrichmentService(new EnrichmentResult(
            logId: 1,
            fields: ['short_summary' => 'Rules summary.'],
            mode: 'rules'
        ));

        $job = new EnrichEventJob($event->id);
        $job->handle($service);

        $event->refresh();
        $this->assertTrue($event->needs_review);
    }

    public function test_handles_exception_gracefully(): void
    {
        $event = $this->createEvent();
        $service = $this->fakeEnrichmentService(exception: new \RuntimeException('API down'));

        Log::shouldReceive('error')
            ->once()
            ->withArgs(fn ($msg) => str_contains($msg, 'enrichment failed'));
        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('warning')->zeroOrMoreTimes();

        $job = new EnrichEventJob($event->id);
        $job->handle($service);

        $event->refresh();
        $this->assertTrue($event->needs_review);
    }
}
