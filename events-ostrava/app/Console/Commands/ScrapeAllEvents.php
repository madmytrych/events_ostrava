<?php

namespace App\Console\Commands;

use App\Services\Scrapers\AllEventsScraper;
use Illuminate\Console\Command;

class ScrapeAllEvents extends Command
{
    protected $signature = 'events:scrape-allevents {--days=60}';
    protected $description = 'Scrape kids events from AllEvents.in';

    public function handle(AllEventsScraper $scraper): int
    {
        $days = (int) $this->option('days');
        if ($days <= 0) {
            $days = 60;
        }

        $count = $scraper->run($days);
        $this->info('Scraped '.$count.' AllEvents events.');

        return self::SUCCESS;
    }
}
