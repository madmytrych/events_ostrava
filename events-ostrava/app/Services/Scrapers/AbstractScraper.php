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

abstract class AbstractScraper implements ScraperInterface
{
    protected const array CZECH_MONTHS = [
        'ledna' => 1, 'února' => 2, 'brezna' => 3, 'března' => 3,
        'dubna' => 4, 'května' => 5, 'cervna' => 6, 'června' => 6,
        'cervence' => 7, 'července' => 7, 'srpna' => 8,
        'září' => 9, 'zari' => 9, 'října' => 10, 'rijna' => 10,
        'listopadu' => 11, 'prosince' => 12,
    ];

    public function __construct(protected readonly EventUpsertService $upsertService) {}

    abstract protected function source(): string;

    /** @return string[] */
    abstract protected function allowedHosts(): array;

    /** @return string[] Detail page URLs to scrape */
    abstract protected function fetchListingUrls(): array;

    abstract protected function parseDetailPage(Crawler $crawler, string $url): ?EventData;

    protected function requestDelayUs(): int
    {
        return 500_000;
    }

    public function run(int $days = 30): int
    {
        $urls = $this->fetchListingUrls();

        $upserted = 0;
        foreach ($urls as $url) {
            try {
                usleep($this->requestDelayUs());

                $html = $this->fetchPage($url);
                if ($html === null) {
                    continue;
                }

                $crawler = new Crawler($html);
                $data = $this->parseDetailPage($crawler, $url);
                if (!$data) {
                    continue;
                }

                $now = Carbon::now('Europe/Prague');
                if ($data->startAt->lt($now) || $data->startAt->gte($now->copy()->addDays($days))) {
                    continue;
                }

                if ($this->upsertService->upsert($data)) {
                    $upserted++;
                }
            } catch (\Throwable $e) {
                Log::warning($this->source() . ': failed to process event URL', [
                    'url' => $url,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $upserted;
    }

    protected function fetchPage(string $url): ?string
    {
        if (!UrlSafety::isAllowedHostUrl($url, $this->allowedHosts())) {
            return null;
        }

        $response = Http::timeout(20)->get($url);
        if (!$response->ok()) {
            return null;
        }

        return $response->body();
    }

    protected function toAbsoluteUrl(string $href, string $baseHref): ?string
    {
        if (!UrlSafety::isAllowedHostUrl($baseHref, $this->allowedHosts())) {
            return null;
        }

        if (preg_match('~^https?://~i', $href)) {
            return UrlSafety::isAllowedHostUrl($href, $this->allowedHosts()) ? $href : null;
        }

        if (str_starts_with($href, '//')) {
            $absolute = 'https:' . $href;

            return UrlSafety::isAllowedHostUrl($absolute, $this->allowedHosts()) ? $absolute : null;
        }

        if (str_starts_with($href, '/')) {
            $absolute = rtrim($baseHref, '/') . $href;

            return UrlSafety::isAllowedHostUrl($absolute, $this->allowedHosts()) ? $absolute : null;
        }

        $absolute = $baseHref . ltrim($href, '/');

        return UrlSafety::isAllowedHostUrl($absolute, $this->allowedHosts()) ? $absolute : null;
    }

    protected function parseCzechDateTime(string $text): ?Carbon
    {
        if (!preg_match('~(\d{1,2})\.\s*([A-Za-zÁÉĚÍÓÚŮÝáéěíóúůýřžščďťň]+)\s*(\d{4}).{0,20}?(\d{1,2}:\d{2})~u', $text, $m)) {
            return null;
        }

        $day = (int) $m[1];
        $monthName = mb_strtolower($m[2]);
        $year = (int) $m[3];
        $time = $m[4];

        $month = static::CZECH_MONTHS[$monthName] ?? null;
        if (!$month) {
            return null;
        }

        [$hh, $mm] = array_map('intval', explode(':', $time));

        return Carbon::create($year, $month, $day, $hh, $mm, 0, 'Europe/Prague');
    }

    /**
     * Parse "22. 3. 2026, 16.00" style Czech date strings
     * where the time uses dots instead of colons.
     */
    protected function parseCzechDateTimeDotted(string $text): ?Carbon
    {
        if (!preg_match('~(\d{1,2})\.\s*(\d{1,2})\.\s*(\d{4})\s*[,;]?\s*(\d{1,2})[.:](\d{2})~u', $text, $m)) {
            return null;
        }

        $day = (int) $m[1];
        $month = (int) $m[2];
        $year = (int) $m[3];
        $hh = (int) $m[4];
        $mm = (int) $m[5];

        if ($month < 1 || $month > 12) {
            return null;
        }

        return Carbon::create($year, $month, $day, $hh, $mm, 0, 'Europe/Prague');
    }

    protected function normalizeWhitespace(string $text): string
    {
        $text = preg_replace('/\s+/', ' ', $text);

        return trim($text);
    }
}
