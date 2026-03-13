<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

Schedule::command('events:scrape ostravainfo --days=7')
    ->cron('0 8,18 * * *')
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('events:scrape visitostrava --days=14')
    ->cron('10 8,18 * * *')
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('events:scrape allevents --days=7')
    ->cron('20 8,18 * * *')
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('events:scrape kulturajih --days=7')
    ->cron('30 8,18 * * *')
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('events:scrape kudyznudy --days=7')
    ->cron('40 8,18 * * *')
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
    ->cron('30 9,19 * * *')
    ->withoutOverlapping()
    ->runInBackground();
