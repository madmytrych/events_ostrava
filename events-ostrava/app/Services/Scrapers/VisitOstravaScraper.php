<?php

declare(strict_types=1);

namespace App\Services\Scrapers;

use App\DTO\EventData;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

final class VisitOstravaScraper extends AbstractScraper
{
    private const string LISTING_URL = 'https://www.visitostrava.eu/cz/akce/rodina/';

    protected function source(): string
    {
        return 'visitostrava';
    }

    protected function allowedHosts(): array
    {
        return ['www.visitostrava.eu', 'visitostrava.eu'];
    }

    /**
     * @throws ConnectionException
     */
    protected function fetchListingUrls(): array
    {
        $urls = [];
        $visited = [];
        $queue = [self::LISTING_URL];

        while ($queue) {
            $pageUrl = \array_shift($queue);
            if (isset($visited[$pageUrl])) {
                continue;
            }
            $visited[$pageUrl] = true;

            $response = Http::timeout(20)->get($pageUrl);
            if (!$response->ok()) {
                Log::warning($this->source() . ': listing page request failed', [
                    'url' => $pageUrl,
                    'status' => $response->status(),
                ]);

                continue;
            }

            $html = $response->body();
            $crawler = new Crawler($html);

            $baseHref = $crawler->filter('base')->first()->attr('href') ?? 'https://www.visitostrava.eu/';
            $baseHref = \rtrim($baseHref, '/') . '/';

            $links = $crawler->filter('a')->each(fn (Crawler $a) => $a->attr('href'));
            foreach ($links as $href) {
                if (!$href) {
                    continue;
                }
                $absolute = $this->toAbsoluteUrl($href, $baseHref);
                if ($absolute === null) {
                    continue;
                }

                if (\preg_match('~^https?://www\.visitostrava\.eu/cz/akce/rodina/(\d+)-[^/]+\.html$~', $absolute)) {
                    $urls[] = $absolute;

                    continue;
                }

                if (\preg_match('~^https?://www\.visitostrava\.eu/cz/akce/rodina/\?from=\d+~', $absolute)) {
                    $queue[] = $absolute;
                }
            }
        }

        return \array_values(\array_unique($urls));
    }

    protected function parseDetailPage(Crawler $crawler, string $url): ?EventData
    {
        // Get the event title from .box-header__title, not the first h1 (which is the site header)
        $title = '';
        if ($crawler->filter('.box-header__title')->count()) {
            $title = \trim($crawler->filter('.box-header__title')->first()->text(''));
        }
        if ($title === '') {
            return null;
        }

        if (!\preg_match('~/cz/akce/rodina/(\d+)-~', $url, $m)) {
            return null;
        }
        $sourceEventId = $m[1];

        $detail = $this->extractDetailInfo($crawler);
        $text = \preg_replace('/\s+/', ' ', $crawler->text());

        $startAt = $detail['start_at'] ?? $this->parseCzechDateTime($text);
        if (!$startAt) {
            return null;
        }

        $venue = $detail['venue'] ?? $this->guessVenue($text);
        $price = $detail['price_text'] ?? $this->guessPrice($text);
        $description = $detail['description'] ?? $this->guessDescription($crawler);
        $address = $detail['address'] ?? $this->guessAddress($text);
        $tags = $detail['tags'] ?? null;
        $ageMin = $detail['age_min'] ?? null;
        $ageMax = $detail['age_max'] ?? null;
        $kidFriendly = $detail['kid_friendly'] ?? null;

        return new EventData(
            source: $this->source(),
            sourceUrl: $url,
            sourceEventId: $sourceEventId,
            title: $title,
            startAt: $startAt,
            endAt: null,
            venue: $venue,
            locationName: $venue,
            address: $address,
            priceText: $price,
            description: $description,
            descriptionRaw: $description,
            ageMin: $ageMin,
            ageMax: $ageMax,
            tags: $tags,
            kidFriendly: $kidFriendly,
            fingerprint: ''
        );
    }

    private function extractDetailInfo(Crawler $crawler): array
    {
        $info = [];

        if ($crawler->filter('.akce-detail .akce-info')->count()) {
            $items = $crawler->filter('.akce-detail .akce-info li')->each(
                fn (Crawler $li) => trim($li->text(''))
            );
            if (count($items) >= 2) {
                $dateStr = $items[0] ?? '';
                $timeStr = $items[1] ?? '';
                $startAt = $this->parseCzechDateTimeFromParts($dateStr, $timeStr);
                if ($startAt) {
                    $info['start_at'] = $startAt;
                }
            }
            if (count($items) >= 3) {
                $venue = trim($items[2] ?? '');
                if ($venue !== '') {
                    $info['venue'] = $venue;
                }
            }
        }

        if ($crawler->filter('.akce-detail .akce-typ strong')->count()) {
            $cat = trim($crawler->filter('.akce-detail .akce-typ strong')->first()->text(''));
            if ($cat !== '') {
                $info['tags'] = [$cat];
            }
        }

        $descParts = [];
        $paras = $crawler->filter('.akce-detail p')->each(
            fn (Crawler $p) => trim($p->text(''))
        );
        foreach ($paras as $p) {
            if ($p === '') {
                continue;
            }
            if (\preg_match('~Doporučený\s+věk:\s*(\d+)\s*\+~ui', $p, $m)) {
                $info['age_min'] = (int) $m[1];
                $info['kid_friendly'] = true;

                continue;
            }
            if (\preg_match('~délka\s+pořadu:\s*(\d+)~ui', $p)) {
                continue;
            }
            $descParts[] = $p;
        }
        if ($descParts) {
            $info['description'] = implode("\n\n", $descParts);
        }

        if ($crawler->filter('.akce-detail-right .likebut')->count()) {
            $priceHtml = $crawler->filter('.akce-detail-right .likebut')->html() ?? '';
            if ($priceHtml !== '') {
                $priceHtml = preg_replace('~</p>~i', ' ', $priceHtml);
                $priceHtml = preg_replace('~<br\s*/?>~i', ' ', $priceHtml);
                $priceText = strip_tags($priceHtml);
            } else {
                $priceText = $crawler->filter('.akce-detail-right .likebut')->text('');
            }
            $priceText = $this->normalizeWhitespace($priceText);
            if ($priceText !== '') {
                $info['price_text'] = $priceText;
            }
        }

        if ($crawler->filter('#map .adresa .cont')->count()) {
            $block = $crawler->filter('#map .adresa .cont')->first();
            $addrLines = [];
            if ($block->filter('h4')->count()) {
                $venue = trim($block->filter('h4')->first()->text(''));
                if ($venue !== '' && empty($info['venue'])) {
                    $info['venue'] = $venue;
                }
            }
            if ($block->filter('p')->count()) {
                $addrLines = $block->filter('p')->each(function (Crawler $p) {
                    $text = trim(preg_replace('/\s+/', ' ', $p->text('')));

                    return $text !== '' ? $text : null;
                });
            }
            $addrLines = array_values(array_filter($addrLines));
            if ($addrLines) {
                $info['address'] = implode(', ', $addrLines);
            }
        }

        return $info;
    }

    private function parseCzechDateTimeFromParts(string $dateStr, string $timeStr): ?Carbon
    {
        if (!\preg_match('~(\d{1,2})\.\s*([A-Za-zÁÉĚÍÓÚŮÝáéěíóúůýřžščďťň]+)\s*(\d{4})~u', $dateStr, $m)) {
            return null;
        }
        $day = (int) $m[1];
        $monthName = mb_strtolower($m[2]);
        $year = (int) $m[3];
        $month = self::CZECH_MONTHS[$monthName] ?? null;
        if (!$month) {
            return null;
        }

        if (!\preg_match('~(\d{1,2}):(\d{2})~', $timeStr, $tm)) {
            return null;
        }
        $hh = (int) $tm[1];
        $mm = (int) $tm[2];

        return Carbon::create($year, $month, $day, $hh, $mm, 0, 'Europe/Prague');
    }

    private function guessVenue(string $text): ?string
    {
        return null;
    }

    private function guessPrice(string $text): ?string
    {
        if (\preg_match('~VSTUPENKY\s*(.{0,80})~u', $text, $m)) {
            $s = trim($m[1]);

            return $s !== '' ? $s : null;
        }

        return null;
    }

    private function guessAddress(string $text): ?string
    {
        if (\preg_match('~Adresa\s*/\s*mapa\s*(.{0,120})~ui', $text, $m)) {
            return trim($m[1]);
        }

        return null;
    }

    private function guessDescription(Crawler $crawler): ?string
    {
        $paras = $crawler->filter('main p')->each(fn (Crawler $p) => trim($p->text('')));
        $paras = array_values(array_filter($paras, static fn ($p) => $p !== ''));

        return $paras ? implode("\n\n", $paras) : null;
    }
}
