<?php

declare(strict_types=1);

namespace App\Services\Scrapers;

use App\DTO\EventData;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

final class OstravaInfoScraper extends AbstractScraper
{
    private const string BASE_URL = 'https://www.ostravainfo.cz';

    private const string LISTING_URL = 'https://www.ostravainfo.cz/cz/akce/rodina/';

    protected function source(): string
    {
        return 'ostravainfo';
    }

    protected function allowedHosts(): array
    {
        return ['www.ostravainfo.cz', 'ostravainfo.cz'];
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

            $baseHref = $crawler->filter('base')->first()->attr('href') ?? self::BASE_URL . '/';
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

                if (\preg_match('~^https?://www\.ostravainfo\.cz/cz/akce/rodina/(\d+)-[^/]+\.html$~', $absolute)) {
                    $urls[] = $absolute;

                    continue;
                }

                if (\preg_match('~^https?://www\.ostravainfo\.cz/cz/akce/rodina/\?from=\d+~', $absolute)) {
                    $queue[] = $absolute;
                }
            }
        }

        return \array_values(\array_unique($urls));
    }

    protected function parseDetailPage(Crawler $crawler, string $url): ?EventData
    {
        $title = \trim($crawler->filter('.akce-detail h2')->first()->text(''));
        if ($title === '') {
            return null;
        }

        if (!\preg_match('~/cz/akce/rodina/(\d+)-~', $url, $m)) {
            return null;
        }
        $sourceEventId = $m[1];

        $detail = $this->extractDetailInfo($crawler);

        $startAt = $detail['start_at'] ?? null;
        if (!$startAt) {
            return null;
        }

        $venue = $detail['venue'] ?? null;
        $price = $detail['price_text'] ?? null;
        $description = $detail['description'] ?? null;
        $address = $detail['address'] ?? null;
        $tags = $detail['tags'] ?? null;
        $kidFriendly = true;

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
            ageMin: null,
            ageMax: null,
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

        if ($crawler->filter('.akce-detail .akce-typ')->count()) {
            $cat = trim($crawler->filter('.akce-detail .akce-typ')->first()->text(''));
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
            $descParts[] = $p;
        }
        if ($descParts) {
            $info['description'] = implode("\n\n", $descParts);
        }

        if ($crawler->filter('.akce-detail-right h3')->count()) {
            $headers = $crawler->filter('.akce-detail-right h3')->each(
                fn (Crawler $h3) => trim($h3->text(''))
            );
            foreach ($headers as $idx => $header) {
                if (mb_strtoupper($header) === 'VSTUPENKY') {
                    $priceNode = $crawler->filter('.akce-detail-right .odsadit')->eq($idx);
                    if ($priceNode->count()) {
                        $priceText = $this->normalizeWhitespace($priceNode->text(''));
                        if ($priceText !== '') {
                            $info['price_text'] = $priceText;
                        }
                    }
                } elseif (mb_strtoupper($header) === 'MÍSTO AKCE') {
                    $venueNode = $crawler->filter('.akce-detail-right .odsadit')->eq($idx);
                    if ($venueNode->count()) {
                        if ($venueNode->filter('h4')->count()) {
                            $venueName = trim($venueNode->filter('h4')->first()->text(''));
                            if ($venueName !== '' && empty($info['venue'])) {
                                $info['venue'] = $venueName;
                            }
                        }
                        if ($venueNode->filter('p')->count()) {
                            $addrLines = $venueNode->filter('p')->each(function (Crawler $p) {
                                $html = $p->html();
                                $html = preg_replace('~<br\s*/?>~i', ', ', $html);
                                $text = trim(strip_tags($html));

                                return $text !== '' ? $text : null;
                            });
                            $addrLines = array_values(array_filter($addrLines));
                            if ($addrLines && count($addrLines) > 0) {
                                $info['address'] = $addrLines[0];
                            }
                        }
                    }
                }
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
}
