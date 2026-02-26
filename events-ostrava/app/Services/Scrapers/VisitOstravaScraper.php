<?php

declare(strict_types=1);

namespace App\Services\Scrapers;

use App\DTO\EventData;
use App\Services\Scrapers\Contracts\ScraperInterface;
use App\Services\Security\UrlSafety;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

class VisitOstravaScraper implements ScraperInterface
{
    private const string SOURCE = 'visitostrava';

    private const string LISTING_URL = 'https://www.visitostrava.eu/cz/akce/rodina/';

    private const array ALLOWED_HOSTS = ['www.visitostrava.eu', 'visitostrava.eu'];

    private const int REQUEST_DELAY_US = 500_000;

    private const array CZECH_MONTHS = [
        'ledna' => 1, 'února' => 2, 'brezna' => 3, 'března' => 3,
        'dubna' => 4, 'května' => 5, 'cervna' => 6, 'června' => 6,
        'cervence' => 7, 'července' => 7, 'srpna' => 8,
        'září' => 9, 'zari' => 9, 'října' => 10, 'rijna' => 10,
        'listopadu' => 11, 'prosince' => 12,
    ];

    public function __construct(private readonly EventUpsertService $upsertService) {}

    public function run(int $days = 14): int
    {
        $urls = $this->fetchListingUrls();

        $upserted = 0;
        foreach ($urls as $url) {
            try {
                usleep(self::REQUEST_DELAY_US);

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
            } catch (\Throwable $e) {
                Log::warning('VisitOstravaScraper: failed to process event URL', [
                    'url' => $url,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $upserted;
    }

    private function fetchListingUrls(): array
    {
        $urls = [];
        $visited = [];
        $queue = [self::LISTING_URL];

        while ($queue) {
            $pageUrl = array_shift($queue);
            if (isset($visited[$pageUrl])) {
                continue;
            }
            $visited[$pageUrl] = true;

            $response = Http::timeout(20)->get($pageUrl);
            if (!$response->ok()) {
                Log::warning('VisitOstravaScraper: listing page request failed', [
                    'url' => $pageUrl,
                    'status' => $response->status(),
                ]);

                continue;
            }

            $html = $response->body();
            $crawler = new Crawler($html);

            $baseHref = $crawler->filter('base')->first()->attr('href') ?? 'https://www.visitostrava.eu/';
            $baseHref = rtrim($baseHref, '/').'/';

            $links = $crawler->filter('a')->each(fn (Crawler $a) => $a->attr('href'));
            foreach ($links as $href) {
                if (!$href) {
                    continue;
                }
                $absolute = $this->toAbsoluteUrl($href, $baseHref);
                if ($absolute === null) {
                    continue;
                }

                if (preg_match('~^https?://www\.visitostrava\.eu/cz/akce/rodina/(\d+)-[^/]+\.html$~', $absolute)) {
                    $urls[] = $absolute;

                    continue;
                }

                if (preg_match('~^https?://www\.visitostrava\.eu/cz/akce/rodina/\?from=\d+~', $absolute)) {
                    $queue[] = $absolute;
                }
            }
        }

        return array_values(array_unique($urls));
    }

    private function toAbsoluteUrl(string $href, string $baseHref): ?string
    {
        if (!UrlSafety::isAllowedHostUrl($baseHref, self::ALLOWED_HOSTS)) {
            return null;
        }

        if (preg_match('~^https?://~i', $href)) {
            return UrlSafety::isAllowedHostUrl($href, self::ALLOWED_HOSTS) ? $href : null;
        }

        if (str_starts_with($href, '//')) {
            $absolute = 'https:'.$href;

            return UrlSafety::isAllowedHostUrl($absolute, self::ALLOWED_HOSTS) ? $absolute : null;
        }

        if (str_starts_with($href, '/')) {
            $absolute = rtrim($baseHref, '/').$href;

            return UrlSafety::isAllowedHostUrl($absolute, self::ALLOWED_HOSTS) ? $absolute : null;
        }

        $absolute = $baseHref.ltrim($href, '/');

        return UrlSafety::isAllowedHostUrl($absolute, self::ALLOWED_HOSTS) ? $absolute : null;
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
            return null;
        }

        if (!preg_match('~/cz/akce/rodina/(\d+)-~', $url, $m)) {
            return null;
        }
        $sourceEventId = $m[1];

        $detail = $this->extractDetailInfo($crawler);
        $text = preg_replace('/\s+/', ' ', $crawler->text());

        $startAt = $detail['start_at'] ?? $this->parseCzechDateTimeFromText($text);
        if (!$startAt) {
            return null;
        }

        $venue = $detail['venue'] ?? $this->guessVenue($crawler, $text);
        $price = $detail['price_text'] ?? $this->guessPrice($crawler, $text);
        $description = $detail['description'] ?? $this->guessDescription($crawler);
        $address = $detail['address'] ?? $this->guessAddress($crawler, $text);
        $tags = $detail['tags'] ?? null;
        $ageMin = $detail['age_min'] ?? null;
        $ageMax = $detail['age_max'] ?? null;
        $kidFriendly = $detail['kid_friendly'] ?? null;

        return new EventData(
            source: self::SOURCE,
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

    private function upsertEvent(EventData $data): bool
    {
        return $this->upsertService->upsert($data);
    }

    private function parseCzechDateTimeFromText(string $text): ?Carbon
    {
        if (!preg_match('~(\d{1,2})\.\s*([A-Za-zÁÉĚÍÓÚŮÝáéěíóúůýřžščďťň]+)\s*(\d{4}).{0,20}?(\d{1,2}:\d{2})~u', $text, $m)) {
            return null;
        }

        $day = (int) $m[1];
        $monthName = mb_strtolower($m[2]);
        $year = (int) $m[3];
        $time = $m[4];

        $month = self::CZECH_MONTHS[$monthName] ?? null;
        if (!$month) {
            return null;
        }

        [$hh, $mm] = array_map('intval', explode(':', $time));

        return Carbon::create($year, $month, $day, $hh, $mm, 0, 'Europe/Prague');
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
            if (preg_match('~Doporučený\s+věk:\s*(\d+)\s*\+~ui', $p, $m)) {
                $info['age_min'] = (int) $m[1];
                $info['kid_friendly'] = true;

                continue;
            }
            if (preg_match('~délka\s+pořadu:\s*(\d+)~ui', $p)) {
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
        if (!preg_match('~(\d{1,2})\.\s*([A-Za-zÁÉĚÍÓÚŮÝáéěíóúůýřžščďťň]+)\s*(\d{4})~u', $dateStr, $m)) {
            return null;
        }
        $day = (int) $m[1];
        $monthName = mb_strtolower($m[2]);
        $year = (int) $m[3];
        $month = self::CZECH_MONTHS[$monthName] ?? null;
        if (!$month) {
            return null;
        }

        if (!preg_match('~(\d{1,2}):(\d{2})~', $timeStr, $tm)) {
            return null;
        }
        $hh = (int) $tm[1];
        $mm = (int) $tm[2];

        return Carbon::create($year, $month, $day, $hh, $mm, 0, 'Europe/Prague');
    }

    private function normalizeWhitespace(string $text): string
    {
        $text = preg_replace('/\s+/', ' ', $text);

        return trim($text);
    }

    private function guessVenue(Crawler $crawler, string $text): ?string
    {
        return null;
    }

    private function guessPrice(Crawler $crawler, string $text): ?string
    {
        if (preg_match('~VSTUPENKY\s*(.{0,80})~u', $text, $m)) {
            $s = trim($m[1]);

            return $s !== '' ? $s : null;
        }

        return null;
    }

    private function guessAddress(Crawler $crawler, string $text): ?string
    {
        if (preg_match('~Adresa\s*/\s*mapa\s*(.{0,120})~ui', $text, $m)) {
            return trim($m[1]);
        }

        return null;
    }

    private function guessDescription(Crawler $crawler): ?string
    {
        $paras = $crawler->filter('main p')->each(fn (Crawler $p) => trim($p->text('')));
        $paras = array_values(array_filter($paras, fn ($p) => $p !== ''));

        return $paras ? implode("\n\n", $paras) : null;
    }
}
