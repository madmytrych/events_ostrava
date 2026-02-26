<?php

declare(strict_types=1);

namespace App\Services\Bot;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramBotService
{
    private string $token;

    public function __construct(?string $token = null)
    {
        $this->token = $token ?? (string) config('services.telegram.bot_token', '');
    }

    public function getUpdates(int $offset, int $timeout): array
    {
        try {
            $response = Http::timeout($timeout + 5)
                ->retry(2, 200)
                ->get($this->apiUrl('getUpdates'), [
                    'timeout' => $timeout,
                    'offset' => $offset,
                ]);

            if (!$response->ok()) {
                return [
                    'ok' => false,
                    'description' => 'HTTP ' . $response->status(),
                ];
            }

            return $response->json();
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'description' => $e->getMessage(),
            ];
        }
    }

    public function sendMessage(
        int $chatId,
        string $text,
        ?array $replyMarkup = null,
        ?string $parseMode = null,
        bool $disableWebPagePreview = true
    ): void {
        $payload = [
            'chat_id' => $chatId,
            'text' => $text,
            'disable_web_page_preview' => $disableWebPagePreview,
        ];

        if ($replyMarkup) {
            $payload['reply_markup'] = $replyMarkup;
        }

        if ($parseMode) {
            $payload['parse_mode'] = $parseMode;
        }

        try {
            $response = Http::timeout(15)
                ->retry(2, 200)
                ->post($this->apiUrl('sendMessage'), $payload);

            if (!$response->ok()) {
                Log::warning('Telegram sendMessage failed', [
                    'status' => $response->status(),
                    'chat_id' => $chatId,
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('Telegram sendMessage error', [
                'chat_id' => $chatId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function answerCallbackQuery(string $callbackQueryId, ?string $text = null): void
    {
        $payload = [
            'callback_query_id' => $callbackQueryId,
        ];

        if ($text) {
            $payload['text'] = $text;
        }

        try {
            $response = Http::timeout(10)
                ->retry(2, 200)
                ->post($this->apiUrl('answerCallbackQuery'), $payload);

            if (!$response->ok()) {
                Log::warning('Telegram answerCallbackQuery failed', [
                    'status' => $response->status(),
                    'callback_query_id' => $callbackQueryId,
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('Telegram answerCallbackQuery error', [
                'callback_query_id' => $callbackQueryId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function apiUrl(string $method): string
    {
        return 'https://api.telegram.org/bot' . $this->token . '/' . $method;
    }
}
