<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\TelegramUser;
use App\Services\Bot\EventQueryService;
use App\Services\Bot\TelegramBotService;
use App\Services\Bot\TelegramEventFormatter;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class TelegramNotify extends Command
{
    protected $signature = 'telegram:notify {--dry-run}';

    protected $description = 'Send weekly reminders about weekend events to Telegram users';

    public function __construct(
        private readonly TelegramBotService $botService,
        private readonly EventQueryService $queryService,
        private readonly TelegramEventFormatter $formatter
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
            ->where('notify_enabled', true)
            ->get();

        foreach ($users as $user) {
            $events = $this->queryService->getWeekendEvents($user->age_min, $user->age_max, 7);

            if ($events->isEmpty()) {
                continue;
            }

            $text = $this->formatter->formatDigest($events, $user->language ?: 'en');

            if (!$dryRun) {
                $this->botService->sendMessage($user->chat_id, $text);
                $user->notify_last_sent_at = Carbon::now($user->timezone);
                $user->save();
            }
        }

        $this->info('Notifications processed.');

        return self::SUCCESS;
    }
}
