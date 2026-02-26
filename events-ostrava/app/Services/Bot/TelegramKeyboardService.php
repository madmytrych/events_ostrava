<?php

declare(strict_types=1);

namespace App\Services\Bot;

final class TelegramKeyboardService
{
    public function __construct(private readonly TelegramTextService $texts) {}

    public function mainMenu(string $lang): array
    {
        return [
            'keyboard' => [
                [
                    ['text' => $this->texts->buttonText($lang, 'today')],
                    ['text' => $this->texts->buttonText($lang, 'tomorrow')],
                ],
                [
                    ['text' => $this->texts->buttonText($lang, 'week')],
                    ['text' => $this->texts->buttonText($lang, 'weekend')],
                ],
                [
                    ['text' => $this->texts->buttonText($lang, 'by_age')],
                    ['text' => $this->texts->buttonText($lang, 'settings')],
                ],
                [
                    ['text' => $this->texts->buttonText($lang, 'submit_event')],
                ],
            ],
            'resize_keyboard' => true,
            'one_time_keyboard' => false,
        ];
    }

    public function age(string $lang): array
    {
        return [
            'keyboard' => [
                [
                    ['text' => $this->texts->ageButtonText($lang, '0-3')],
                    ['text' => $this->texts->ageButtonText($lang, '3-6')],
                ],
                [
                    ['text' => $this->texts->ageButtonText($lang, '6-10')],
                    ['text' => $this->texts->ageButtonText($lang, 'all')],
                ],
                [
                    ['text' => $this->texts->buttonText($lang, 'back')],
                ],
            ],
            'resize_keyboard' => true,
            'one_time_keyboard' => false,
        ];
    }

    public function settings(string $lang, bool $notifyEnabled, ?bool $notifyNewEvents = false): array
    {
        return [
            'keyboard' => [
                [
                    ['text' => $this->texts->buttonText($lang, 'change_language')],
                ],
                [
                    ['text' => $this->texts->settingsNotifyButtonText($lang, $notifyEnabled)],
                    ['text' => $this->texts->settingsNewEventsButtonText($lang, $notifyNewEvents)],
                ],
                [
                    ['text' => $this->texts->buttonText($lang, 'about')],
                ],
                [
                    ['text' => $this->texts->buttonText($lang, 'back')],
                ],
            ],
            'resize_keyboard' => true,
            'one_time_keyboard' => false,
        ];
    }

    public function submissionSkip(string $lang): array
    {
        return [
            'keyboard' => [
                [
                    ['text' => $this->texts->buttonText($lang, 'skip')],
                ],
                [
                    ['text' => $this->texts->buttonText($lang, 'cancel')],
                ],
            ],
            'resize_keyboard' => true,
            'one_time_keyboard' => false,
        ];
    }

    public function submissionCancel(string $lang): array
    {
        return [
            'keyboard' => [
                [
                    ['text' => $this->texts->buttonText($lang, 'cancel')],
                ],
            ],
            'resize_keyboard' => true,
            'one_time_keyboard' => false,
        ];
    }

    public function language(): array
    {
        return [
            'inline_keyboard' => [
                [
                    ['text' => $this->texts->languageButtonLabel('uk'), 'callback_data' => 'lang:uk'],
                ],
                [
                    ['text' => $this->texts->languageButtonLabel('en'), 'callback_data' => 'lang:en'],
                ],
                [
                    ['text' => $this->texts->languageButtonLabel('cs'), 'callback_data' => 'lang:cs'],
                ],
            ],
        ];
    }
}
