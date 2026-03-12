<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

Schedule::command('events:scrape visitostrava --days=14')
    ->twiceDaily(6, 18)
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('events:scrape allevents --days=60')
    ->twiceDaily(7, 19)
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('events:scrape kulturajih --days=30')
    ->twiceDaily(8, 20)
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('events:scrape kudyznudy --days=30')
    ->twiceDaily(9, 21)
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('events:enrich --limit=15')
    ->everyFourHours()
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('events:deactivate-past --grace-hours=2')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('telegram:notify')
    ->weeklyOn(5, '08:00')
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('telegram:notify-new')
    ->dailyAt('10:00')
    ->withoutOverlapping()
    ->runInBackground();
