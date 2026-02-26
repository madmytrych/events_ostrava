<?php

declare(strict_types=1);

namespace App\Services\Bot;

use App\Models\TelegramUser;
use Illuminate\Database\UniqueConstraintViolationException;

final class TelegramMessageHandler
{
    private const array ALLOWED_LANGUAGES = ['uk', 'en', 'cs'];

    private const array EVENT_ACTIONS = [
        'today' => ['getTodayEvents',    'label_today'],
        'tomorrow' => ['getTomorrowEvents', 'label_tomorrow'],
        'week' => ['getWeekEvents',     'label_week'],
        'weekend' => ['getWeekendEvents',  'label_weekend'],
    ];

    private const array ALLOWED_AGE_RANGES = [
        '0-3' => [0, 3],
        '3-6' => [3, 6],
        '6-10' => [6, 10],
    ];

    public function __construct(
        private readonly EventQueryService $queryService,
        private readonly TelegramKeyboardService $keyboards,
        private readonly TelegramTextService $texts,
        private readonly TelegramEventFormatter $formatter,
        private readonly TelegramSubmissionService $submissions,
    ) {}

    /**
     * @return array{text: string, reply_markup?: mixed, parse_mode?: string|null}|null
     */
    public function handle(int $chatId, string $text): ?array
    {
        $rawText = trim($text);
        if ($rawText === '') {
            return null;
        }

        [$command] = preg_split('/\s+/', $rawText);
        $command = strtolower(explode('@', $command)[0]);

        $user = $this->getOrCreateUser($chatId);

        if ($this->isLanguageSelection($rawText)) {
            $lang = $this->languageFromSelection($rawText);
            if ($lang) {
                $user->language = $lang;
                $user->save();

                return [
                    'text' => $this->texts->savedNotice($lang) . "\n\n" . $this->texts->text($lang, 'features'),
                    'reply_markup' => $this->keyboards->mainMenu($lang),
                ];
            }
        }

        if (!$user->language) {
            return [
                'text' => $this->texts->multiLanguageWelcome() . "\n\n" . $this->texts->text('en', 'language_prompt'),
                'reply_markup' => $this->keyboards->language(),
            ];
        }

        $lang = $this->getUserLanguage($user);

        if ($command === '/start' || $command === '/help') {
            return [
                'text' => $this->texts->text($lang, 'welcome'),
                'reply_markup' => $this->keyboards->mainMenu($lang),
            ];
        }

        $action = $this->resolveAction($rawText, $lang);

        if ($action === 'settings') {
            return [
                'text' => $this->texts->settingsText($lang, $user->notify_enabled),
                'reply_markup' => $this->keyboards->settings($lang, $user->notify_enabled),
            ];
        }

        if ($action === 'change_language') {
            return [
                'text' => $this->texts->text($lang, 'language_prompt'),
                'reply_markup' => $this->keyboards->language(),
            ];
        }

        if ($action === 'about') {
            return [
                'text' => $this->texts->text($lang, 'about'),
                'reply_markup' => $this->keyboards->settings($lang, $user->notify_enabled),
            ];
        }

        if ($action === 'back') {
            return [
                'text' => $this->texts->text($lang, 'menu_help'),
                'reply_markup' => $this->keyboards->mainMenu($lang),
            ];
        }

        if ($action === 'submit_event') {
            $this->submissions->start($user);

            return [
                'text' => $this->texts->text($lang, 'submit_intro') . "\n\n" . $this->texts->text($lang, 'submit_ask_url'),
                'reply_markup' => $this->keyboards->submissionCancel($lang),
            ];
        }

        if ($this->submissions->isInProgress($user)) {
            return $this->submissions->handleInput($user, $rawText, $lang, $action);
        }

        if ($action === 'toggle_notify') {
            $user->notify_enabled = !$user->notify_enabled;
            $user->save();
            $messageKey = $user->notify_enabled ? 'notify_on' : 'notify_off';

            return [
                'text' => $this->texts->savedNotice($lang) . "\n\n" . $this->texts->text($lang, $messageKey),
                'reply_markup' => $this->keyboards->settings($lang, $user->notify_enabled),
            ];
        }

        if ($action === 'by_age') {
            return [
                'text' => $this->texts->text($lang, 'age_prompt'),
                'reply_markup' => $this->keyboards->age($lang),
            ];
        }

        $ageRange = $this->parseAgeRange($rawText);

        if ($ageRange !== null && !str_starts_with($command, '/')) {
            $this->saveAgePreference($user, $ageRange[0], $ageRange[1]);

            return [
                'text' => $this->texts->savedNotice($lang) . "\n\n" . $this->texts->ageSavedText($lang, $ageRange[0], $ageRange[1]),
                'reply_markup' => $this->keyboards->mainMenu($lang),
            ];
        }

        if ($command === '/age' && $ageRange !== null) {
            $this->saveAgePreference($user, $ageRange[0], $ageRange[1]);

            return [
                'text' => $this->texts->savedNotice($lang) . "\n\n" . $this->texts->ageSavedText($lang, $ageRange[0], $ageRange[1]),
                'reply_markup' => $this->keyboards->mainMenu($lang),
            ];
        }

        if ($command === '/notify') {
            $actionValue = strtolower(trim((string) str_replace('/notify', '', $rawText)));
            if ($actionValue === 'on') {
                $user->notify_enabled = true;
                $user->save();

                return [
                    'text' => $this->texts->savedNotice($lang) . "\n\n" . $this->texts->text($lang, 'notify_on'),
                    'reply_markup' => $this->keyboards->settings($lang, $user->notify_enabled),
                ];
            }
            if ($actionValue === 'off') {
                $user->notify_enabled = false;
                $user->save();

                return [
                    'text' => $this->texts->savedNotice($lang) . "\n\n" . $this->texts->text($lang, 'notify_off'),
                    'reply_markup' => $this->keyboards->settings($lang, $user->notify_enabled),
                ];
            }
        }

        if ($command === '/prefs') {
            return [
                'text' => $this->formatPrefs($user, $lang),
                'reply_markup' => $this->keyboards->settings($lang, $user->notify_enabled),
            ];
        }

        $eventResponse = $this->handleEventQuery($command, $action, $ageRange, $user, $lang);
        if ($eventResponse !== null) {
            return $eventResponse;
        }

        if (!str_starts_with($command, '/')) {
            return [
                'text' => $this->texts->text($lang, 'use_buttons'),
                'reply_markup' => $this->keyboards->mainMenu($lang),
            ];
        }

        return [
            'text' => $this->texts->text($lang, 'menu_help'),
            'reply_markup' => $this->keyboards->mainMenu($lang),
        ];
    }

    private function handleEventQuery(
        string $command,
        ?string $action,
        ?array $ageRange,
        TelegramUser $user,
        string $lang,
    ): ?array {
        foreach (self::EVENT_ACTIONS as $key => [$method, $labelKey]) {
            if ($command === "/{$key}" || $action === $key) {
                [$min, $max] = $ageRange ?? $this->getUserAgeRange($user);
                $events = $this->queryService->{$method}($min, $max);
                $response = $this->formatter->formatEventsResponse($labelKey, $events, $lang);
                $response['reply_markup'] = $this->keyboards->mainMenu($lang);

                return $response;
            }
        }

        return null;
    }

    private function parseAgeRange(string $text): ?array
    {
        $text = trim($text);
        if (in_array($text, $this->allAgesButtons(), true)) {
            return [null, null];
        }

        if (!preg_match('/\b(\d+)\s*[–-]\s*(\d+)\b/u', $text, $m)) {
            return null;
        }

        $min = (int) $m[1];
        $max = (int) $m[2];
        $key = "{$min}-{$max}";

        return self::ALLOWED_AGE_RANGES[$key] ?? null;
    }

    private function getUserAgeRange(TelegramUser $user): array
    {
        return [$user->age_min, $user->age_max];
    }

    private function getOrCreateUser(int $chatId): TelegramUser
    {
        try {
            return TelegramUser::firstOrCreate(
                ['chat_id' => $chatId],
                ['timezone' => 'Europe/Prague']
            );
        } catch (UniqueConstraintViolationException $e) {
            $existing = TelegramUser::query()->where('chat_id', $chatId)->first();
            if ($existing !== null) {
                return $existing;
            }
            throw $e;
        }
    }

    private function saveAgePreference(TelegramUser $user, ?int $ageMin, ?int $ageMax): void
    {
        if ($ageMin !== null && $ageMax !== null) {
            $key = "{$ageMin}-{$ageMax}";
            if (!array_key_exists($key, self::ALLOWED_AGE_RANGES)) {
                return;
            }
        }

        $user->age_min = $ageMin;
        $user->age_max = $ageMax;
        $user->save();
    }

    private function formatPrefs(TelegramUser $user, string $lang): string
    {
        $age = $this->texts->text($lang, 'all_ages');
        if ($user->age_min !== null && $user->age_max !== null) {
            $age = $user->age_min . '–' . $user->age_max;
        }

        $notify = $user->notify_enabled
            ? $this->texts->text($lang, 'notify_state_on')
            : $this->texts->text($lang, 'notify_state_off');

        return implode("\n", [
            $this->texts->text($lang, 'prefs_title'),
            $this->texts->replacePlaceholders($this->texts->text($lang, 'prefs_language'), [
                'value' => $this->languageName($lang, $this->getUserLanguage($user)),
            ]),
            $this->texts->replacePlaceholders($this->texts->text($lang, 'prefs_age'), [
                'value' => $age,
            ]),
            $this->texts->replacePlaceholders($this->texts->text($lang, 'prefs_notify'), [
                'value' => $notify,
            ]),
        ]);
    }

    public function getUserLanguage(TelegramUser $user): string
    {
        $lang = $user->language ? strtolower(trim($user->language)) : null;

        return in_array($lang, self::ALLOWED_LANGUAGES, true) ? $lang : 'en';
    }

    private function resolveAction(string $text, string $lang): ?string
    {
        $text = trim($text);
        $map = [
            'today' => $this->texts->buttonText($lang, 'today'),
            'tomorrow' => $this->texts->buttonText($lang, 'tomorrow'),
            'week' => $this->texts->buttonText($lang, 'week'),
            'weekend' => $this->texts->buttonText($lang, 'weekend'),
            'by_age' => $this->texts->buttonText($lang, 'by_age'),
            'settings' => $this->texts->buttonText($lang, 'settings'),
            'change_language' => $this->texts->buttonText($lang, 'change_language'),
            'about' => $this->texts->buttonText($lang, 'about'),
            'back' => $this->texts->buttonText($lang, 'back'),
            'submit_event' => $this->texts->buttonText($lang, 'submit_event'),
            'skip' => $this->texts->buttonText($lang, 'skip'),
            'cancel' => $this->texts->buttonText($lang, 'cancel'),
        ];

        foreach ($map as $action => $label) {
            if ($text === $label) {
                return $action;
            }
        }

        if ($this->isWeeklyToggle($text, $lang)) {
            return 'toggle_notify';
        }

        return null;
    }

    private function isWeeklyToggle(string $text, string $lang): bool
    {
        $text = trim($text);
        $on = $this->texts->settingsNotifyButtonText($lang, true);
        $off = $this->texts->settingsNotifyButtonText($lang, false);

        return $text === $on || $text === $off;
    }

    private function isLanguageSelection(string $text): bool
    {
        return str_starts_with($text, 'lang:');
    }

    private function languageFromSelection(string $text): ?string
    {
        $lang = strtolower(trim((string) str_replace('lang:', '', $text)));

        return in_array($lang, self::ALLOWED_LANGUAGES, true) ? $lang : null;
    }

    private function allAgesButtons(): array
    {
        return [
            $this->texts->ageButtonText('en', 'all'),
            $this->texts->ageButtonText('uk', 'all'),
            $this->texts->ageButtonText('cs', 'all'),
        ];
    }

    private function languageName(string $lang, string $targetLang): string
    {
        $names = [
            'en' => ['en' => 'English', 'uk' => 'Ukrainian', 'cs' => 'Czech'],
            'uk' => ['en' => 'Англійська', 'uk' => 'Українська', 'cs' => 'Чеська'],
            'cs' => ['en' => 'Angličtina', 'uk' => 'Ukrajinština', 'cs' => 'Čeština'],
        ];

        return $names[$lang][$targetLang] ?? $names['en'][$targetLang] ?? $targetLang;
    }
}
