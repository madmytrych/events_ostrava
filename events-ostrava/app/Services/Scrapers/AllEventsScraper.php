<?php

declare(strict_types=1);

namespace App\Services\Scrapers;

use App\DTO\EventData;
use App\Services\Security\UrlSafety;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

final class AllEventsScraper extends AbstractScraper
{
    private const string LISTING_URL = 'https://allevents.in/ostrava/kids';

    protected function source(): string
    {
        return 'allevents';
    }

    protected function allowedHosts(): array
    {
        return ['allevents.in', 'www.allevents.in'];
    }

    protected function fetchListingUrls(): array
    {
        $response = Http::timeout(20)->get(self::LISTING_URL);
        if (!$response->ok()) {
            Log::warning($this->source() . ': listing page request failed', [
                'status' => $response->status(),
            ]);

            return [];
        }

        $html = $response->body();
        $crawler = new Crawler($html);

        $urls = [];
        $links = $crawler->filter('a')->each(fn (Crawler $a) => $a->attr('href'));

        foreach ($links as $href) {
            if (!$href || !is_string($href)) {
                continue;
            }
            if (!UrlSafety::isAllowedHostUrl($href, $this->allowedHosts())) {
                continue;
            }
            if (preg_match('~^https?://allevents\.in/ostrava/[^"\']+/(\d{6,})$~', $href)) {
                $urls[] = $href;
            }
        }

        return array_values(array_unique($urls));
    }

    protected function parseDetailPage(Crawler $crawler, string $url): ?EventData
    {
        $title = trim($crawler->filter('h1')->first()->text(''));
        if ($title === '') {
            $title = trim($crawler->filter('title')->first()->text(''));
            $title = preg_replace('~\s*\|\s*AllEvents.*$~', '', $title);
        }
        if ($title === '') {
            return null;
        }

        if (!preg_match('~/(\d{6,})$~', $url, $m)) {
            return null;
        }
        $sourceEventId = $m[1];

        $text = $crawler->text();
        $startAt = $this->parseEnglishDateFromText($text);
        if (!$startAt) {
            return null;
        }

        [$locationName, $address] = $this->extractLocationFromText($text);
        $description = $this->extractDescriptionFromText($text);

        return new EventData(
            source: $this->source(),
            sourceUrl: $url,
            sourceEventId: $sourceEventId,
            title: $title,
            startAt: $startAt,
            endAt: null,
            venue: $locationName,
            locationName: $locationName,
            address: $address,
            priceText: null,
            description: $description,
            descriptionRaw: $description,
            ageMin: null,
            ageMax: null,
            tags: null,
            kidFriendly: null,
            fingerprint: ''
        );
    }

    private function parseEnglishDateFromText(string $text): ?Carbon
    {
        if (!preg_match('~([A-Za-z]{3},\s+\d{1,2}\s+[A-Za-z]{3},\s+\d{4}\s+at\s+\d{1,2}:\d{2}\s+[ap]m)~', $text, $m)) {
            return null;
        }

        try {
            return Carbon::createFromFormat('D, j M, Y \a\t g:i a', $m[1], 'Europe/Prague');
        } catch (\Throwable) {
            return null;
        }
    }

    private function extractLocationFromText(string $text): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $text);
        $lines = array_values(array_filter(array_map('trim', $lines)));

        $locationName = null;
        $address = null;

        foreach ($lines as $idx => $line) {
            if (preg_match('~^[A-Za-z]{3},\s+\d{1,2}\s+[A-Za-z]{3},\s+\d{4}\s+at\s+\d{1,2}:\d{2}\s+[ap]m$~', $line)) {
                $locationName = $lines[$idx + 1] ?? null;
                $address = $lines[$idx + 2] ?? null;
                break;
            }
        }

        if ($locationName === null && preg_match('~Ostrava~i', $text)) {
            $locationName = 'Ostrava';
        }

        return [$locationName, $address];
    }

    private function extractDescriptionFromText(string $text): ?string
    {
        if (preg_match('~About the event\s*(.+?)\s*Also check out~s', $text, $m)) {
            $desc = trim(preg_replace('/\s+/', ' ', $m[1]));

            return $desc !== '' ? $desc : null;
        }

        return null;
    }
}
