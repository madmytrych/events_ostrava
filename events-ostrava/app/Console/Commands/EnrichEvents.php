<?php

namespace App\Console\Commands;

use App\Jobs\EnrichEventJob;
use App\Models\Event;
use Illuminate\Console\Command;

class EnrichEvents extends Command
{
    protected $signature = 'events:enrich {--limit=50}';
    protected $description = 'Dispatch enrichment jobs for events missing summary';

    public function handle(): int
    {
        $limit = (int) $this->option('limit');
        if ($limit <= 0) {
            $limit = 50;
        }

        $events = Event::query()
            ->whereNull('short_summary')
            ->where('status', '!=', 'rejected')
            ->where('is_active', true)
            ->orderBy('start_at')
            ->limit($limit)
            ->get(['id']);

        foreach ($events as $event) {
            EnrichEventJob::dispatch($event->id);
        }

        $this->info('Dispatched '.$events->count().' enrichment jobs.');
        return self::SUCCESS;
    }
}
