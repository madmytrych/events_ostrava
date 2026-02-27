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

final class KulturaJihScraper extends AbstractScraper
{
    private const string BASE_URL = 'https://www.kulturajih.cz/';

    private const string LISTING_URL = 'https://www.kulturajih.cz/cz/kultura/detska-akce/';

    protected function source(): string
    {
        return 'kulturajih';
    }

    protected function allowedHosts(): array
    {
        return ['www.kulturajih.cz', 'kulturajih.cz'];
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
        $crawler->filter('article.articleakce')->each(function (Crawler $article) use (&$urls) {
            $link = $article->filter('.article__title a, .article__image a')->first();
            if (!$link->count()) {
                return;
            }

            $href = $link->attr('href');
            if (!$href) {
                return;
            }

            $absolute = $this->toAbsoluteUrl($href, self::BASE_URL);
            if ($absolute === null) {
                return;
            }

            if (preg_match('~cz/kultura/(\d+)-[^/]+\.html$~', $absolute)) {
                $urls[] = $absolute;
            }
        });

        return array_values(array_unique($urls));
    }

    /**
     * @throws ConnectionException
     */
    protected function fetchPage(string $url): ?string
    {
        if (!$this->isAllowedUrl($url)) {
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
        $title = trim($crawler->filter('h1')->first()->text(''));
        if ($title === '') {
            return null;
        }

        if (!preg_match('~/(\d+)-[^/]+\.html$~', $url, $m)) {
            return null;
        }
        $sourceEventId = $m[1];

        $startAt = $this->extractDateTime($crawler);
        if (!$startAt) {
            return null;
        }

        $venue = $this->extractVenue($crawler);
        $price = $this->extractPrice($crawler);
        $description = $this->extractDescription($crawler);

        return new EventData(
            source: $this->source(),
            sourceUrl: $url,
            sourceEventId: $sourceEventId,
            title: $title,
            startAt: $startAt,
            endAt: null,
            venue: $venue,
            locationName: $venue,
            address: null,
            priceText: $price,
            description: $description,
            descriptionRaw: $description,
            ageMin: null,
            ageMax: null,
            tags: ['Dětské akce'],
            kidFriendly: true,
            fingerprint: ''
        );
    }

    private function extractDateTime(Crawler $crawler): ?Carbon
    {
        if ($crawler->filter('table.vstupenky th')->count()) {
            $dateText = trim($crawler->filter('table.vstupenky th')->first()->text(''));
            $parsed = $this->parseCzechDateTimeDotted($dateText);
            if ($parsed) {
                return $parsed;
            }
        }

        if ($crawler->filter('h2.termin')->count()) {
            $text = $this->normalizeWhitespace($crawler->filter('h2.termin')->first()->ancestors()->first()->text(''));
            $parsed = $this->parseCzechDateTimeDotted($text);
            if ($parsed) {
                return $parsed;
            }
        }

        $fullText = $this->normalizeWhitespace($crawler->text());

        return $this->parseCzechDateTimeDotted($fullText);
    }

    private function extractVenue(Crawler $crawler): ?string
    {
        if ($crawler->filter('ul.akce-info li.misto a')->count()) {
            $venue = trim($crawler->filter('ul.akce-info li.misto a')->first()->text(''));
            if ($venue !== '') {
                return $venue;
            }
        }

        if ($crawler->filter('ul.akce-info li.misto')->count()) {
            $venue = trim($crawler->filter('ul.akce-info li.misto')->first()->text(''));
            if ($venue !== '') {
                return $venue;
            }
        }

        return null;
    }

    private function extractPrice(Crawler $crawler): ?string
    {
        if ($crawler->filter('ul.akce-info li')->count()) {
            $items = $crawler->filter('ul.akce-info li')->each(
                fn (Crawler $li) => trim($li->text(''))
            );
            foreach ($items as $item) {
                if (preg_match('~Vstupn[eé]:\s*(.+)~ui', $item, $m)) {
                    return $this->normalizeWhitespace(strip_tags($m[1]));
                }
            }
        }

        if ($crawler->filter('span.cena')->count()) {
            $price = trim($crawler->filter('span.cena')->first()->text(''));
            if ($price !== '') {
                return $price;
            }
        }

        return null;
    }

    private function extractDescription(Crawler $crawler): ?string
    {
        $paras = $crawler->filter('.zajezd-item-detail p.MsoNormal')->each(
            fn (Crawler $p) => trim($p->text(''))
        );
        $paras = array_values(array_filter($paras, fn (string $p) => $p !== ''));

        if ($paras) {
            return implode("\n\n", $paras);
        }

        $contentParas = $crawler->filter('.zajezd-item-detail .text p')->each(
            fn (Crawler $p) => trim($p->text(''))
        );
        $contentParas = array_values(array_filter($contentParas, fn (string $p) => $p !== ''));

        return $contentParas ? implode("\n\n", $contentParas) : null;
    }

    private function isAllowedUrl(string $url): bool
    {
        return UrlSafety::isAllowedHostUrl($url, $this->allowedHosts());
    }
}
