<?php

namespace App\Console\Commands;

use App\Models\Event;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class DeactivatePastEvents extends Command
{
    protected $signature = 'events:deactivate-past {--grace-hours=0}';
    protected $description = 'Mark past events as inactive';

    public function handle(): int
    {
        $graceHours = max(0, (int) $this->option('grace-hours'));
        $now = Carbon::now('Europe/Prague')->subHours($graceHours);

        $count = Event::query()
            ->where('is_active', true)
            ->where(function ($query) use ($now) {
                $query->whereNotNull('end_at')->where('end_at', '<', $now)
                    ->orWhere(function ($q) use ($now) {
                        $q->whereNull('end_at')->where('start_at', '<', $now);
                    });
            })
            ->update(['is_active' => false]);

        Log::info('Deactivated past events', ['count' => $count]);
        $this->info("Deactivated {$count} past events.");

        return self::SUCCESS;
    }
}
