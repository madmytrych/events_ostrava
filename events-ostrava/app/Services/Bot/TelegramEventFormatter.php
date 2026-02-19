<?php

namespace App\Services\Bot;

use App\Models\Event;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class TelegramEventFormatter
{
    public function __construct(private TelegramTextService $texts)
    {
    }

    public function formatEventsResponse(string $labelKey, $events, string $lang): array
    {
        if ($events->isEmpty()) {
            return [
                'text' => $this->texts->replacePlaceholders(
                    $this->texts->text($lang, 'no_events'),
                    ['label' => $this->texts->text($lang, $labelKey)]
                ),
                'parse_mode' => null,
            ];
        }

        $limit = 7;
        $lines = [];
        foreach ($events->take($limit) as $event) {
            $lines[] = $this->formatEvent($event, $lang);
        }

        return [
            'text' => implode("\n\n" . $this->texts->eventDivider() . "\n\n", $lines),
            'parse_mode' => null,
        ];
    }

    public function formatDigest($events, string $lang): string
    {
        $lines = [$this->texts->text($lang, 'digest_title')];
        foreach ($events->take(7) as $event) {
            $lines[] = $this->formatEvent($event, $lang);
        }

        return implode("\n\n" . $this->texts->eventDivider() . "\n\n", $lines);
    }

    public function formatEvent(Event $event, string $lang): string
    {
        $title = trim((string) $this->eventText($event, 'title', $lang));
        if ($title === '') {
            $title = $this->texts->text($lang, 'event_title');
        }

        $summary = $this->shortSummary(
            $this->eventText($event, 'short_summary', $lang)
                ?: $this->eventText($event, 'summary', $lang)
        );
        if ($summary === '') {
            $summary = $this->texts->text($lang, 'summary_fallback');
        }

        $detailsLine = '🔗 ' . $this->texts->text($lang, 'details');
        if ($event->source_url) {
            $detailsLine .= ': ' . $event->source_url;
        }

        return implode("\n", [
            '🎨 ' . $title,
            '👶 ' . $this->texts->text($lang, 'age_label') . ': ' . $this->formatAge($event, $lang),
            '🕒 ' . $this->formatTimeLine($event, $lang),
            '📍 ' . $this->formatLocation($event, $lang),
            '🏠 ' . $this->formatIndoorOutdoor($event, $lang),
            '',
            $summary,
            '',
            $detailsLine,
        ]);
    }

    private function shortSummary(string $text): string
    {
        $summary = trim(preg_replace('/\s+/', ' ', (string) $text));
        return Str::limit($summary, 200, '…');
    }

    private function eventText(Event $event, string $field, string $lang): string
    {
        $map = [
            'title' => $event->title_i18n ?? null,
            'summary' => $event->summary_i18n ?? null,
            'short_summary' => $event->short_summary_i18n ?? null,
        ];

        $translated = $map[$field] ?? null;
        if (is_array($translated) && isset($translated[$lang]) && is_string($translated[$lang])) {
            $value = trim($translated[$lang]);
            if ($value !== '') {
                return $value;
            }
        }

        return (string) ($event->{$field} ?? '');
    }

    private function formatAge(Event $event, string $lang): string
    {
        if ($event->age_min === null && $event->age_max === null) {
            return $this->texts->text($lang, 'all_ages');
        }

        if ($event->age_min !== null && $event->age_max !== null) {
            return $event->age_min . '–' . $event->age_max;
        }

        if ($event->age_min !== null) {
            return $this->texts->replacePlaceholders($this->texts->text($lang, 'age_from'), [
                'age' => (string) $event->age_min,
            ]);
        }

        return $this->texts->replacePlaceholders($this->texts->text($lang, 'age_to'), [
            'age' => (string) $event->age_max,
        ]);
    }

    private function formatTimeLine(Event $event, string $lang): string
    {
        if (!$event->start_at) {
            return $this->texts->text($lang, 'time_unknown');
        }

        $start = Carbon::parse($event->start_at)
            ->timezone('Europe/Prague')
            ->locale($this->carbonLocale($lang));

        if (!$event->end_at) {
            return $start->translatedFormat('D H:i');
        }

        $end = Carbon::parse($event->end_at)
            ->timezone('Europe/Prague')
            ->locale($this->carbonLocale($lang));

        if ($start->toDateString() === $end->toDateString()) {
            return $start->translatedFormat('D H:i') . '–' . $end->translatedFormat('H:i');
        }

        return $start->translatedFormat('D H:i') . '–' . $end->translatedFormat('D H:i');
    }

    private function formatLocation(Event $event, string $lang): string
    {
        $location = trim((string) ($event->location_name ?? $event->venue ?? $event->address ?? ''));
        return $location !== '' ? $location : $this->texts->text($lang, 'location_unknown');
    }

    private function formatIndoorOutdoor(Event $event, string $lang): string
    {
        $value = strtolower(trim((string) $event->indoor_outdoor));
        if ($value === 'indoor') {
            return $this->texts->text($lang, 'indoor');
        }
        if ($value === 'outdoor') {
            return $this->texts->text($lang, 'outdoor');
        }
        if ($value === 'both') {
            return $this->texts->text($lang, 'indoor_outdoor');
        }

        return $this->texts->text($lang, 'indoor_unknown');
    }

    private function carbonLocale(string $lang): string
    {
        return match ($lang) {
            'uk' => 'uk_UA',
            'cs' => 'cs_CZ',
            default => 'en',
        };
    }
}
