<?php

declare(strict_types=1);

namespace App\Services\Bot;

use Illuminate\Support\Facades\Lang;

final class TelegramTextService
{
    public function text(string $lang, string $key): string
    {
        return Lang::get('telegram.' . $key, [], $lang);
    }

    public function replacePlaceholders(string $text, array $replacements): string
    {
        foreach ($replacements as $key => $value) {
            $text = str_replace(':' . $key, $value, $text);
        }

        return $text;
    }

    public function savedNotice(string $lang): string
    {
        return $this->text($lang, 'saved_notice');
    }

    public function ageSavedText(string $lang, ?int $min, ?int $max): string
    {
        if ($min === null && $max === null) {
            return $this->text($lang, 'age_saved_all');
        }

        return $this->replacePlaceholders(
            $this->text($lang, 'age_saved'),
            ['range' => $min . '–' . $max]
        );
    }

    public function settingsText(string $lang, bool $notifyEnabled, ?bool $notifyNewEvents = false): string
    {
        $weeklyState = $notifyEnabled
            ? $this->text($lang, 'notify_state_on')
            : $this->text($lang, 'notify_state_off');

        $newEventsState = ($notifyNewEvents ?? false)
            ? $this->text($lang, 'notify_state_on')
            : $this->text($lang, 'notify_state_off');

        return implode("\n", [
            $this->text($lang, 'settings_title'),
            $this->replacePlaceholders($this->text($lang, 'settings_weekly_status'), [
                'value' => $weeklyState,
            ]),
            $this->replacePlaceholders($this->text($lang, 'settings_new_events_status'), [
                'value' => $newEventsState,
            ]),
        ]);
    }

    public function multiLanguageWelcome(): string
    {
        $locale = 'en';

        return implode("\n\n", [
            $this->text($locale, 'lang_flag_en') . $this->text('en', 'welcome'),
            $this->text($locale, 'lang_flag_uk') . $this->text('uk', 'welcome'),
            $this->text($locale, 'lang_flag_cs') . $this->text('cs', 'welcome'),
        ]);
    }

    public function eventDivider(string $lang = 'en'): string
    {
        return $this->text($lang, 'event_divider');
    }

    public function buttonText(string $lang, string $key): string
    {
        return $this->text($lang, 'buttons.' . $key);
    }

    public function ageButtonText(string $lang, string $key): string
    {
        $map = [
            '0-3' => 'age_0_3',
            '3-6' => 'age_3_6',
            '6-10' => 'age_6_10',
            'all' => 'age_all',
        ];

        return $this->text($lang, 'buttons.' . ($map[$key] ?? $key));
    }

    public function settingsNotifyButtonText(string $lang, bool $enabled): string
    {
        $state = $enabled
            ? $this->text($lang, 'notify_state_on')
            : $this->text($lang, 'notify_state_off');

        return $this->text($lang, 'buttons.weekly_reminders') . ': ' . $state;
    }

    public function settingsNewEventsButtonText(string $lang, ?bool $enabled): string
    {
        $state = ($enabled ?? false)
            ? $this->text($lang, 'notify_state_on')
            : $this->text($lang, 'notify_state_off');

        return $this->text($lang, 'buttons.new_event_alerts') . ': ' . $state;
    }

    public function languageButtonLabel(string $langCode): string
    {
        return $this->text($langCode, 'lang_flag_' . $langCode)
            . $this->text($langCode, 'lang_label_' . $langCode);
    }
}
