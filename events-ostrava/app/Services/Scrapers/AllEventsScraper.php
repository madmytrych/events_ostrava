<?php

namespace App\Services\Scrapers;

use App\DTO\EventData;
use App\Services\Scrapers\Contracts\ScraperInterface;
use App\Services\Security\UrlSafety;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Symfony\Component\DomCrawler\Crawler;

class AllEventsScraper implements ScraperInterface
{
    private const SOURCE = 'allevents';

    private const LISTING_URL = 'https://allevents.in/ostrava/kids';

    private const ALLOWED_HOSTS = ['allevents.in', 'www.allevents.in'];

    public function __construct(private readonly EventUpsertService $upsertService) {}

    public function run(int $days = 60): int
    {
        $urls = $this->fetchListingUrls();

        $upserted = 0;
        foreach ($urls as $url) {
            $data = $this->fetchAndParseDetail($url);
            if (!$data) {
                continue;
            }

            $now = Carbon::now('Europe/Prague');
            if ($data->startAt->lt($now) || $data->startAt->gte($now->copy()->addDays($days))) {
                continue;
            }

            if ($this->upsertEvent($data)) {
                $upserted++;
            }
        }

        return $upserted;
    }

    private function fetchListingUrls(): array
    {
        $html = Http::timeout(20)->get(self::LISTING_URL)->throw()->body();
        preg_match_all('~https?://allevents\.in/ostrava/[^"\']+/(\d{6,})~', $html, $matches);

        $urls = array_unique($matches[0] ?? []);

        return array_values($urls);
    }

    private function fetchAndParseDetail(string $url): ?EventData
    {
        if (!UrlSafety::isAllowedHostUrl($url, self::ALLOWED_HOSTS)) {
            return null;
        }

        $response = Http::timeout(20)->get($url);
        if (!$response->ok()) {
            return null;
        }

        $html = $response->body();
        $crawler = new Crawler($html);

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
        $startAt = $this->parseDateFromText($text);
        if (!$startAt) {
            return null;
        }

        [$locationName, $address] = $this->extractLocationFromText($text);
        $description = $this->extractDescriptionFromText($text) ?? null;

        return new EventData(
            source: self::SOURCE,
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

    private function parseDateFromText(string $text): ?Carbon
    {
        if (!preg_match('~([A-Za-z]{3},\s+\d{1,2}\s+[A-Za-z]{3},\s+\d{4}\s+at\s+\d{1,2}:\d{2}\s+[ap]m)~', $text, $m)) {
            return null;
        }
        $timezone = 'UTC';
        if (in_array('Europe/Prague', \DateTimeZone::listIdentifiers(), true)) {
            $timezone = 'Europe/Prague';
        }

        try {
            return Carbon::createFromFormat('D, j M, Y \a\t g:i a', $m[1], $timezone);
        } catch (\Throwable $e) {
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

    private function upsertEvent(EventData $data): bool
    {
        return $this->upsertService->upsert($data);
    }
}
