<?php

declare(strict_types=1);

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    protected function schedule(Schedule $schedule): void
    {
        $schedule->command('events:scrape visitostrava --days=14')
            ->twiceDaily(6, 18)
            ->withoutOverlapping()
            ->runInBackground();

        $schedule->command('events:scrape allevents --days=60')
            ->twiceDaily(7, 19)
            ->withoutOverlapping()
            ->runInBackground();

        $schedule->command('events:scrape kulturajih --days=30')
            ->twiceDaily(8, 20)
            ->withoutOverlapping()
            ->runInBackground();

        $schedule->command('events:scrape kudyznudy --days=30')
            ->twiceDaily(9, 21)
            ->withoutOverlapping()
            ->runInBackground();

        $schedule->command('events:enrich --limit=15')
            ->everyFourHours()
            ->withoutOverlapping()
            ->runInBackground();

        $schedule->command('events:deactivate-past --grace-hours=2')
            ->hourly()
            ->withoutOverlapping()
            ->runInBackground();

        $schedule->command('telegram:notify')
            ->weeklyOn(5, '08:00')
            ->withoutOverlapping()
            ->runInBackground();

        $schedule->command('telegram:notify-new')
            ->dailyAt('10:00')
            ->withoutOverlapping()
            ->runInBackground();
    }
}
