<?php

declare(strict_types=1);

namespace App\Services\Scrapers\Contracts;

interface ScraperInterface
{
    public function run(int $days = 30): int;
}
