<?php

declare(strict_types=1);

return [
    'sources' => [
        'visitostrava' => [
            'class' => \App\Services\Scrapers\VisitOstravaScraper::class,
            'default_days' => 14,
        ],
        'allevents' => [
            'class' => \App\Services\Scrapers\AllEventsScraper::class,
            'default_days' => 60,
        ],
        'kulturajih' => [
            'class' => \App\Services\Scrapers\KulturaJihScraper::class,
            'default_days' => 30,
        ],
        'kudyznudy' => [
            'class' => \App\Services\Scrapers\KudyZNudyScraper::class,
            'default_days' => 30,
        ],
    ],
];
