<?php

namespace App\Jobs;

use App\Models\Event;
use App\Services\Enrichment\EnrichmentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class EnrichEventJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $eventId) {}

    /**
     * Execute the job.
     */
    public function handle(EnrichmentService $service): void
    {
        $event = Event::query()->find($this->eventId);
        if (!$event) {
            Log::warning('EnrichEventJob: event not found', ['event_id' => $this->eventId]);

            return;
        }

        if ($event->duplicate_of_event_id) {
            Log::info('EnrichEventJob: duplicate event skipped', ['event_id' => $this->eventId]);

            return;
        }

        if ($event->short_summary && $event->enriched_at) {
            Log::info('EnrichEventJob: already enriched', ['event_id' => $this->eventId]);

            return;
        }

        $event->increment('enrichment_attempts');

        try {
            $result = $service->enrich($event);

            $event->fill($result->fields);
            if (!$event->summary && $event->short_summary) {
                $event->summary = $event->short_summary;
            }

            $event->enrichment_log_id = $result->logId;
            $event->enriched_at = now();
            $event->needs_review = $result->mode !== 'ai';
            $event->save();

            Log::info('EnrichEventJob: enriched', ['event_id' => $this->eventId]);
        } catch (\Throwable $e) {
            $event->needs_review = true;
            $event->save();
            Log::error('EnrichEventJob: enrichment failed', [
                'event_id' => $this->eventId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
