<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Bot\TelegramBotService;
use App\Services\Bot\TelegramMessageHandler;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class TelegramPoll extends Command
{
    protected $signature = 'telegram:poll {--once} {--timeout=30} {--sleep=2}';

    protected $description = 'Poll Telegram updates and respond to commands';

    public function __construct(
        private readonly TelegramBotService $botService,
        private readonly TelegramMessageHandler $handler,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $token = config('services.telegram.bot_token');
        if (!$token) {
            Log::error('telegram:poll aborted — TELEGRAM_BOT_TOKEN is not set');
            $this->error('Missing TELEGRAM_BOT_TOKEN in .env');

            return self::FAILURE;
        }

        $offset = (int) Cache::get('telegram:last_update_id', 0);
        $timeout = max(1, (int) $this->option('timeout'));
        $sleep = max(0, (int) $this->option('sleep'));
        $once = (bool) $this->option('once');

        do {
            $updates = $this->botService->getUpdates($offset + 1, $timeout);
            if (!($updates['ok'] ?? false)) {
                Log::warning('telegram:poll getUpdates failed', [
                    'description' => $updates['description'] ?? 'unknown',
                    'offset' => $offset,
                ]);
                $this->warn('Telegram getUpdates failed: ' . ($updates['description'] ?? 'unknown'));
                if ($once) {
                    return self::FAILURE;
                }
                sleep($sleep);

                continue;
            }

            foreach (($updates['result'] ?? []) as $update) {
                $offset = (int) ($update['update_id'] ?? $offset);
                Cache::put('telegram:last_update_id', $offset);

                $callback = $update['callback_query'] ?? null;
                if ($callback) {
                    $chatId = (int) data_get($callback, 'message.chat.id');
                    $data = trim((string) data_get($callback, 'data', ''));
                    if ($chatId && $data !== '') {
                        $response = $this->handler->handle($chatId, $data);
                        $this->botService->answerCallbackQuery((string) ($callback['id'] ?? ''));
                        $this->sendResponse($chatId, $response);
                    }

                    continue;
                }

                $message = $update['message'] ?? $update['edited_message'] ?? null;
                if (!$message) {
                    continue;
                }

                $text = trim((string) ($message['text'] ?? ''));
                if ($text === '') {
                    continue;
                }

                $chatId = (int) data_get($message, 'chat.id');
                $response = $this->handler->handle($chatId, $text);
                $this->sendResponse($chatId, $response);
            }

            if ($once) {
                break;
            }

            if ($sleep > 0) {
                sleep($sleep);
            }
        } while (true);

        return self::SUCCESS;
    }

    private function sendResponse(int $chatId, ?array $response): void
    {
        if ($response === null) {
            return;
        }

        $this->botService->sendMessage(
            $chatId,
            $response['text'],
            $response['reply_markup'] ?? null,
            $response['parse_mode'] ?? null,
        );
    }
}
