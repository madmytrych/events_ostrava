<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

class ScrapeVisitOstrava extends Command
{
    protected $signature = 'events:scrape-visitostrava {--days=14}';

    protected $description = 'Scrape VisitOstrava family events (alias for events:scrape visitostrava)';

    public function handle(): int
    {
        return $this->call('events:scrape', [
            'source' => 'visitostrava',
            '--days' => $this->option('days'),
        ]);
    }
}
