<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Scrapers\Contracts\ScraperInterface;
use Illuminate\Console\Command;

class ScrapeEvents extends Command
{
    protected $signature = 'events:scrape {source} {--days=}';

    protected $description = 'Scrape events from a configured source';

    public function handle(): int
    {
        $source = $this->argument('source');
        $sources = config('scrapers.sources', []);

        if (!isset($sources[$source])) {
            $this->error("Unknown source '{$source}'. Available: " . implode(', ', array_keys($sources)));

            return self::FAILURE;
        }

        $config = $sources[$source];
        $class = $config['class'];
        $defaultDays = (int) ($config['default_days'] ?? 30);

        $days = $this->option('days') !== null
            ? (int) $this->option('days')
            : $defaultDays;

        if ($days <= 0) {
            $days = $defaultDays;
        }

        /** @var ScraperInterface $scraper */
        $scraper = app($class);

        $this->info("Scraping '{$source}' (next {$days} days)...");

        $count = $scraper->run($days);

        $this->info("Done. Upserted {$count} events from '{$source}'.");

        return self::SUCCESS;
    }
}
