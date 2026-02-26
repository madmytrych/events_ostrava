<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

class ScrapeVisitOstrava extends Command
{
    protected $signature = 'events:scrape-visitostrava {--days=14}';

    protected $description = 'Scrape VisitOstrava family events and upsert into DB';

    public function handle(\App\Services\Scrapers\VisitOstravaScraper $scraper): int
    {
        $days = (int) $this->option('days');
        if ($days <= 0) {
            $days = 14;
        }

        $count = $scraper->run($days);

        $this->info("Upserted {$count} events.");

        return self::SUCCESS;
    }
}
