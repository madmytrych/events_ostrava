<?php

declare(strict_types=1);

return [
    'sources' => [
        // Official source - run FIRST (highest priority)
        'ostravainfo' => [
            'class' => \App\Services\Scrapers\OstravaInfoScraper::class,
            'default_days' => 30,
            'priority' => 1,
        ],
        // Secondary sources - may contain duplicates of ostravainfo
        'visitostrava' => [
            'class' => \App\Services\Scrapers\VisitOstravaScraper::class,
            'default_days' => 14,
            'priority' => 2,
        ],
        'allevents' => [
            'class' => \App\Services\Scrapers\AllEventsScraper::class,
            'default_days' => 60,
            'priority' => 2,
        ],
        'kulturajih' => [
            'class' => \App\Services\Scrapers\KulturaJihScraper::class,
            'default_days' => 30,
            'priority' => 2,
        ],
        'kudyznudy' => [
            'class' => \App\Services\Scrapers\KudyZNudyScraper::class,
            'default_days' => 30,
            'priority' => 2,
        ],
    ],
];
