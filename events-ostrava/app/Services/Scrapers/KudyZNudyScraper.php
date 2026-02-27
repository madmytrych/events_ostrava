<?php

declare(strict_types=1);

namespace App\Services\Scrapers;

use App\DTO\EventData;
use App\Services\Security\UrlSafety;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

final class KudyZNudyScraper extends AbstractScraper
{
    private const string BASE_URL = 'https://www.kudyznudy.cz';

    private const string LISTING_URL = 'https://www.kudyznudy.cz/kam-pojedete/moravskoslezsky-kraj/ostravsko/ostrava';

    protected function source(): string
    {
        return 'kudyznudy';
    }

    protected function allowedHosts(): array
    {
        return ['www.kudyznudy.cz', 'kudyznudy.cz'];
    }

    /**
     * @throws ConnectionException
     */
    protected function fetchListingUrls(): array
    {
        $response = Http::timeout(20)
            ->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; EventsOstravaBot/1.0)'])
            ->get(self::LISTING_URL);

        if (!$response->ok()) {
            Log::warning($this->source() . ': listing page request failed', [
                'status' => $response->status(),
            ]);

            return [];
        }

        $html = $response->body();
        $crawler = new Crawler($html);

        $urls = [];

        $crawler->filter('.events-box .item a')->each(function (Crawler $a) use (&$urls) {
            $href = $a->attr('href');
            if (!$href || !preg_match('~^/akce/[a-z0-9-]+~', $href)) {
                return;
            }

            $href = preg_replace('~\?.*$~', '', $href);
            $absolute = self::BASE_URL . $href;
            $urls[] = $absolute;
        });

        return array_values(array_unique($urls));
    }

    protected function fetchPage(string $url): ?string
    {
        if (!UrlSafety::isAllowedHostUrl($url, $this->allowedHosts())) {
            return null;
        }

        $response = Http::timeout(20)
            ->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; EventsOstravaBot/1.0)'])
            ->get($url);

        if (!$response->ok()) {
            return null;
        }

        return $response->body();
    }

    protected function parseDetailPage(Crawler $crawler, string $url): ?EventData
    {
        $jsonLd = $this->extractEventJsonLd($crawler);
        if (!$jsonLd) {
            return null;
        }

        $title = $jsonLd['name'] ?? '';
        if ($title === '') {
            return null;
        }

        $startAt = $this->parseIso8601($jsonLd['startDate'] ?? '');
        if (!$startAt) {
            return null;
        }

        if (!$this->isOstravaEvent($jsonLd)) {
            return null;
        }

        $endAt = $this->parseIso8601($jsonLd['endDate'] ?? '');

        $venue = $jsonLd['location']['name'] ?? null;
        $address = $this->buildAddress($jsonLd['location']['address'] ?? []);

        $description = $jsonLd['description'] ?? null;
        $fullDescription = $this->extractFullDescription($crawler);
        if ($fullDescription) {
            $description = $fullDescription;
        }

        $priceText = $this->extractPriceTag($crawler);
        $tags = $this->extractTags($crawler);
        $kidFriendly = in_array('Vhodné pro děti', $tags, true) ? true : null;

        $sourceEventId = $this->extractSlug($url);
        if (!$sourceEventId) {
            return null;
        }

        return new EventData(
            source: $this->source(),
            sourceUrl: $url,
            sourceEventId: $sourceEventId,
            title: $title,
            startAt: $startAt,
            endAt: $endAt,
            venue: $venue,
            locationName: $venue,
            address: $address,
            priceText: $priceText,
            description: $description,
            descriptionRaw: $description,
            ageMin: null,
            ageMax: null,
            tags: $tags ?: null,
            kidFriendly: $kidFriendly,
            fingerprint: ''
        );
    }

    private function extractEventJsonLd(Crawler $crawler): ?array
    {
        $scripts = $crawler->filter('script[type="application/ld+json"]');
        if (!$scripts->count()) {
            return null;
        }

        $result = null;
        $scripts->each(function (Crawler $script) use (&$result) {
            if ($result) {
                return;
            }
            $json = json_decode(trim($script->text()), true, 512, JSON_THROW_ON_ERROR);
            if (is_array($json) && ($json['@type'] ?? '') === 'Event') {
                $result = $json;
            }
        });

        if ($result) {
            return $result;
        }

        $text = $crawler->text();
        if (preg_match('~"@type"\s*:\s*"Event".*?"startDate"\s*:\s*"([^"]+)"~s', $text, $m)) {
            $block = $this->findJsonBlockContaining($crawler, '"@type": "Event"');
            if ($block) {
                return $block;
            }
        }

        return null;
    }

    private function findJsonBlockContaining(Crawler $crawler, string $needle): ?array
    {
        $html = $crawler->html();
        $pos = strpos($html, $needle);
        if ($pos === false) {
            return null;
        }

        $start = strrpos(substr($html, 0, $pos), '{');
        if ($start === false) {
            return null;
        }

        $depth = 0;
        $len = strlen($html);
        for ($i = $start; $i < $len; $i++) {
            if ($html[$i] === '{') {
                $depth++;
            } elseif ($html[$i] === '}') {
                $depth--;
            }
            if ($depth === 0) {
                $jsonStr = substr($html, $start, $i - $start + 1);
                $decoded = json_decode($jsonStr, true);

                return is_array($decoded) ? $decoded : null;
            }
        }

        return null;
    }

    private function parseIso8601(string $dateStr): ?Carbon
    {
        if ($dateStr === '') {
            return null;
        }

        try {
            return Carbon::parse($dateStr)->setTimezone('Europe/Prague');
        } catch (\Throwable) {
            return null;
        }
    }

    private function isOstravaEvent(array $jsonLd): bool
    {
        $address = $jsonLd['location']['address'] ?? [];
        $locality = mb_strtolower($address['addressLocality'] ?? '');
        $region = mb_strtolower($address['addressRegion'] ?? '');

        if (str_contains($locality, 'ostrava')) {
            return true;
        }

        if ($region === 'moravskoslezský kraj') {
            return true;
        }

        $venueName = mb_strtolower($jsonLd['location']['name'] ?? '');

        return str_contains($venueName, 'ostrava');
    }

    private function buildAddress(array $addr): ?string
    {
        $parts = array_filter([
            $addr['streetAddress'] ?? '',
            trim(($addr['postalCode'] ?? '') . ' ' . ($addr['addressLocality'] ?? '')),
        ]);

        $result = implode(', ', $parts);

        return $result !== '' ? $result : null;
    }

    private function extractFullDescription(Crawler $crawler): ?string
    {
        $summary = '';
        if ($crawler->filter('.content-summary')->count()) {
            $summary = $this->normalizeWhitespace($crawler->filter('.content-summary')->first()->text(''));
        }

        $body = '';
        if ($crawler->filter('#content-description')->count()) {
            $body = $this->normalizeWhitespace($crawler->filter('#content-description')->first()->text(''));
        }

        $parts = array_filter([$summary, $body]);

        return $parts ? implode("\n\n", $parts) : null;
    }

    private function extractPriceTag(Crawler $crawler): ?string
    {
        $tags = $crawler->filter('.tag-label')->each(
            fn (Crawler $span) => trim($span->text(''))
        );

        foreach ($tags as $tag) {
            if (mb_strtolower($tag) === 'zdarma') {
                return 'Zdarma';
            }
        }

        return null;
    }

    /** @return string[] */
    private function extractTags(Crawler $crawler): array
    {
        return array_values(array_filter(
            $crawler->filter('.tag-label')->each(
                fn (Crawler $span) => trim($span->text(''))
            ),
            static fn (string $t) => $t !== ''
        ));
    }

    private function extractSlug(string $url): ?string
    {
        if (preg_match('~/akce/([a-z0-9-]+)~', $url, $m)) {
            return $m[1];
        }

        return null;
    }
}
