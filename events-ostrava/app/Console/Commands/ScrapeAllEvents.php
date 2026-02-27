<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

class ScrapeAllEvents extends Command
{
    protected $signature = 'events:scrape-allevents {--days=60}';

    protected $description = 'Scrape kids events from AllEvents.in (alias for events:scrape allevents)';

    public function handle(): int
    {
        return $this->call('events:scrape', [
            'source' => 'allevents',
            '--days' => $this->option('days'),
        ]);
    }
}
