<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\EnrichEventJob;
use App\Models\Event;
use Illuminate\Console\Command;

class EnrichEvents extends Command
{
    protected $signature = 'events:enrich {--limit=15} {--retry-failed}';

    protected $description = 'Dispatch enrichment jobs for events missing summary';

    private const int MAX_ENRICHMENT_ATTEMPTS = 5;

    public function handle(): int
    {
        $limit = (int) $this->option('limit');
        if ($limit <= 0) {
            $limit = 50;
        }

        $query = Event::query()
            ->where('status', '!=', 'rejected')
            ->where('is_active', true)
            ->orderBy('start_at');

        if ($this->option('retry-failed')) {
            $query->whereNull('enriched_at')
                ->where('enrichment_attempts', '>', 0)
                ->where('enrichment_attempts', '<', self::MAX_ENRICHMENT_ATTEMPTS);
        } else {
            $query->whereNull('short_summary');
        }

        $events = $query->limit($limit)->get(['id']);

        foreach ($events as $index => $event) {
            EnrichEventJob::dispatch($event->id)->delay($index * 3);
        }

        $this->info('Dispatched ' . $events->count() . ' enrichment jobs.');

        return self::SUCCESS;
    }
}
