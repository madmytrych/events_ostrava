<?php

namespace App\Console\Commands;

use App\Models\TelegramUser;
use App\Services\Bot\EventQueryService;
use App\Services\Bot\TelegramEventFormatter;
use App\Services\Bot\TelegramBotService;
use App\Services\Bot\TelegramKeyboardService;
use App\Services\Bot\TelegramSubmissionService;
use App\Services\Bot\TelegramTextService;
use Illuminate\Console\Command;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;

class TelegramPoll extends Command
{
    private const ALLOWED_LANGUAGES = ['uk', 'en', 'cs'];

    protected $signature = 'telegram:poll {--once} {--timeout=30} {--sleep=2}';
    protected $description = 'Poll Telegram updates and respond to commands';

    public function __construct(
        private readonly TelegramBotService      $botService,
        private readonly EventQueryService       $queryService,
        private readonly TelegramKeyboardService $keyboards,
        private readonly TelegramTextService     $texts,
        private readonly TelegramEventFormatter  $formatter,
        private readonly TelegramSubmissionService $submissions
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

        $offset = (int) Cache::get('telegram:last_update_id', 0);
        $timeout = max(1, (int) $this->option('timeout'));
        $sleep = max(0, (int) $this->option('sleep'));
        $once = (bool) $this->option('once');

        do {
            $updates = $this->botService->getUpdates($offset + 1, $timeout);
            if (!($updates['ok'] ?? false)) {
                $this->warn('Telegram getUpdates failed.');
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
                        $response = $this->handleCommand($chatId, $data);
                        $this->botService->answerCallbackQuery((string) ($callback['id'] ?? ''));
                        if ($response) {
                            $this->botService->sendMessage(
                                $chatId,
                                $response['text'],
                                $response['reply_markup'] ?? null,
                                $response['parse_mode'] ?? null
                            );
                        }
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
                $response = $this->handleCommand($chatId, $text);
                if ($response) {
                    $this->botService->sendMessage(
                        $chatId,
                        $response['text'],
                        $response['reply_markup'] ?? null,
                        $response['parse_mode'] ?? null
                    );
                }
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

    private function handleCommand(int $chatId, string $text): ?array
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

        if ($command === '/today' || $action === 'today') {
            [$min, $max] = $ageRange ?? $this->getUserAgeRange($user);
            $events = $this->queryService->getTodayEvents($min, $max);
            $response = $this->formatter->formatEventsResponse('label_today', $events, $lang);
            $response['reply_markup'] = $this->keyboards->mainMenu($lang);
            return $response;
        }

        if ($command === '/tomorrow' || $action === 'tomorrow') {
            [$min, $max] = $ageRange ?? $this->getUserAgeRange($user);
            $events = $this->queryService->getTomorrowEvents($min, $max);
            $response = $this->formatter->formatEventsResponse('label_tomorrow', $events, $lang);
            $response['reply_markup'] = $this->keyboards->mainMenu($lang);
            return $response;
        }

        if ($command === '/week' || $action === 'week') {
            [$min, $max] = $ageRange ?? $this->getUserAgeRange($user);
            $events = $this->queryService->getWeekEvents($min, $max);
            $response = $this->formatter->formatEventsResponse('label_week', $events, $lang);
            $response['reply_markup'] = $this->keyboards->mainMenu($lang);
            return $response;
        }

        if ($command === '/weekend' || $action === 'weekend') {
            [$min, $max] = $ageRange ?? $this->getUserAgeRange($user);
            $events = $this->queryService->getWeekendEvents($min, $max);
            $response = $this->formatter->formatEventsResponse('label_weekend', $events, $lang);
            $response['reply_markup'] = $this->keyboards->mainMenu($lang);
            return $response;
        }

        if (!str_starts_with($command, '/')) {
            return [
                'text' => $this->texts->text($lang, 'use_buttons'),
                'reply_markup' => $this->keyboards->mainMenu($lang),
            ];
        }

        return [
            'text' => $this->helpText($lang),
            'reply_markup' => $this->keyboards->mainMenu($lang),
        ];
    }

    private function parseAgeRange(string $text): ?array
    {
        $text = trim($text);
        foreach ($this->allAgesButtons() as $label) {
            if ($text === $label) {
                return [null, null];
            }
        }

        if (!preg_match('/\b(\d+)\s*[–-]\s*(\d+)\b/u', $text, $m)) {
            return null;
        }

        $min = (int) $m[1];
        $max = (int) $m[2];

        $allowed = $this->allowedAgeRanges();

        $key = "{$min}-{$max}";
        return $allowed[$key] ?? null;
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
            if (!array_key_exists($key, $this->allowedAgeRanges())) {
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

    private function helpText(string $lang): string
    {
        return $this->texts->text($lang, 'menu_help');
    }

    private function getUserLanguage(TelegramUser $user): string
    {
        $lang = $user->language ? strtolower(trim($user->language)) : null;
        return in_array($lang, self::ALLOWED_LANGUAGES, true) ? $lang : 'en';
    }

    private function allowedAgeRanges(): array
    {
        return [
            '0-3' => [0, 3],
            '3-6' => [3, 6],
            '6-10' => [6, 10],
        ];
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
