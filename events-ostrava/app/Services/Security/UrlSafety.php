<?php

declare(strict_types=1);

namespace App\Services\Security;

final class UrlSafety
{
    public static function isPublicHttpUrl(string $url): bool
    {
        $host = self::parseHttpHost($url);
        return $host !== null && self::isPublicHost($host);
    }

    public static function isAllowedHostUrl(string $url, array $allowedHosts): bool
    {
        $host = self::parseHttpHost($url);
        if ($host === null) {
            return false;
        }
        $allowed = array_map('strtolower', $allowedHosts);
        return in_array($host, $allowed, true) && self::isPublicHost($host);
    }

    /** @return string|null Normalized host if URL is http(s) and host is not local/private. */
    private static function parseHttpHost(string $url): ?string
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return null;
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true)) {
            return null;
        }
        return self::extractHost($parts);
    }

    private static function extractHost(array $parts): ?string
    {
        $host = strtolower(trim((string) ($parts['host'] ?? '')));
        if ($host === '') {
            return null;
        }

        if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
            return null;
        }

        if ($host === 'localdomain' || str_ends_with($host, '.localdomain')) {
            return null;
        }

        if ($host === 'local' || str_ends_with($host, '.local')) {
            return null;
        }

        return $host;
    }

    private static function isPublicHost(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return (bool) filter_var(
                $host,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            );
        }

        $ips = gethostbynamel($host);
        if (!$ips) {
            return false;
        }

        foreach ($ips as $ip) {
            $isPublic = (bool) filter_var(
                $ip,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            );
            if (!$isPublic) {
                return false;
            }
        }

        return true;
    }
}
