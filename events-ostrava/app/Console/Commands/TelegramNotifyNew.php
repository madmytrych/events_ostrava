<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\TelegramUser;
use App\Services\Bot\EventQueryService;
use App\Services\Bot\TelegramBotService;
use App\Services\Bot\TelegramEventFormatter;
use App\Services\Bot\TelegramMessageHandler;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class TelegramNotifyNew extends Command
{
    protected $signature = 'telegram:notify-new {--dry-run}';

    protected $description = 'Send notifications about newly scraped events matching user age preferences';

    public function __construct(
        private readonly TelegramBotService $botService,
        private readonly EventQueryService $queryService,
        private readonly TelegramEventFormatter $formatter,
        private readonly TelegramMessageHandler $handler,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $token = config('services.telegram.bot_token');
        if (!$token) {
            $this->error('Missing TELEGRAM_BOT_TOKEN in .env');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        $users = TelegramUser::query()
            ->where('notify_new_events', true)
            ->get();

        $sent = 0;

        foreach ($users as $user) {
            $since = $user->notify_new_events_last_sent_at ?? $user->created_at;

            $events = $this->queryService->getNewEventsSince(
                Carbon::parse($since),
                $user->age_min,
                $user->age_max,
            );

            $now = Carbon::now($user->timezone ?? 'Europe/Prague');

            if ($events->isEmpty()) {
                if (!$dryRun) {
                    $user->notify_new_events_last_sent_at = $now;
                    $user->save();
                }

                continue;
            }

            $lang = $this->handler->getUserLanguage($user);
            $text = $this->formatter->formatNewEventsAlert($events, $lang);

            if (!$dryRun) {
                $this->botService->sendMessage($user->chat_id, $text);
                $user->notify_new_events_last_sent_at = $now;
                $user->save();
            }

            $sent++;
        }

        $this->info("Notifications processed. Sent to {$sent} user(s).");

        return self::SUCCESS;
    }
}
