<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Security;

use App\Services\Security\UrlSafety;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class UrlSafetyTest extends TestCase
{
    #[DataProvider('publicHttpUrlProvider')]
    public function test_is_public_http_url(string $url, bool $expected): void
    {
        $this->assertSame($expected, UrlSafety::isPublicHttpUrl($url));
    }

    public static function publicHttpUrlProvider(): array
    {
        return [
            'valid https' => ['https://example.com', true],
            'valid http' => ['http://example.com/path?q=1', true],
            'ftp rejected' => ['ftp://example.com', false],
            'file rejected' => ['file:///etc/passwd', false],
            'javascript rejected' => ['javascript:alert(1)', false],
            'empty string' => ['', false],
            'localhost rejected' => ['http://localhost', false],
            'localhost subdomain rejected' => ['http://foo.localhost', false],
            'local domain rejected' => ['http://myapp.local', false],
            'localdomain rejected' => ['http://myapp.localdomain', false],
            '127.0.0.1 rejected' => ['http://127.0.0.1', false],
            'no scheme' => ['example.com', false],
        ];
    }

    #[DataProvider('allowedHostUrlProvider')]
    public function test_is_allowed_host_url(string $url, array $allowed, bool $expected): void
    {
        $this->assertSame($expected, UrlSafety::isAllowedHostUrl($url, $allowed));
    }

    public static function allowedHostUrlProvider(): array
    {
        $hosts = ['www.visitostrava.eu', 'visitostrava.eu'];

        return [
            'exact match' => ['https://www.visitostrava.eu/page', $hosts, true],
            'without www' => ['https://visitostrava.eu/page', $hosts, true],
            'case insensitive' => ['https://WWW.VISITOSTRAVA.EU/page', $hosts, true],
            'not in list' => ['https://evil.com/page', $hosts, false],
            'localhost not allowed' => ['http://localhost', $hosts, false],
            'empty url' => ['', $hosts, false],
            'non-http scheme' => ['ftp://www.visitostrava.eu', $hosts, false],
        ];
    }
}
