<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class ScrapeVisitOstrava extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'events:scrape-visitostrava {--days=14}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Scrape VisitOstrava family events and upsert into DB';

    public function handle(\App\Services\Scrapers\VisitOstravaScraper $scraper): int
    {
        $days = (int) $this->option('days');
        $count = $scraper->run($days);

        $this->info("Upserted {$count} events.");

        return self::SUCCESS;
    }
}
