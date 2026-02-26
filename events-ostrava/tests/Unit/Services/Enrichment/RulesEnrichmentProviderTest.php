<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Enrichment;

use App\Services\Enrichment\Providers\RulesEnrichmentProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class RulesEnrichmentProviderTest extends TestCase
{
    private RulesEnrichmentProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provider = new RulesEnrichmentProvider();
    }

    private function callDeriveFields(array $input): array
    {
        $method = new ReflectionMethod(RulesEnrichmentProvider::class, 'deriveFields');

        return $method->invoke($this->provider, $input);
    }

    private function callExtractAgeRange(string $text): array
    {
        $method = new ReflectionMethod(RulesEnrichmentProvider::class, 'extractAgeRange');

        return $method->invoke($this->provider, $text);
    }

    private function callDetectKidFriendly(string $text, ?int $ageMin, ?int $ageMax): ?bool
    {
        $method = new ReflectionMethod(RulesEnrichmentProvider::class, 'detectKidFriendly');

        return $method->invoke($this->provider, $text, $ageMin, $ageMax);
    }

    private function callDetectIndoorOutdoor(string $text): string
    {
        $method = new ReflectionMethod(RulesEnrichmentProvider::class, 'detectIndoorOutdoor');

        return $method->invoke($this->provider, $text);
    }

    private function callDetectCategory(string $text): string
    {
        $method = new ReflectionMethod(RulesEnrichmentProvider::class, 'detectCategory');

        return $method->invoke($this->provider, $text);
    }

    private function callDetectLanguage(string $text): string
    {
        $method = new ReflectionMethod(RulesEnrichmentProvider::class, 'detectLanguage');

        return $method->invoke($this->provider, $text);
    }

    private function callBuildSummary(array $input): ?string
    {
        $method = new ReflectionMethod(RulesEnrichmentProvider::class, 'buildSummary');

        return $method->invoke($this->provider, $input);
    }

    // --- Age extraction ---

    #[DataProvider('ageRangeProvider')]
    public function test_extract_age_range(string $text, ?int $expectedMin, ?int $expectedMax): void
    {
        $normalizeMethod = new ReflectionMethod(RulesEnrichmentProvider::class, 'normalizeText');
        $normalized = $normalizeMethod->invoke($this->provider, $text);

        [$min, $max] = $this->callExtractAgeRange($normalized);
        $this->assertSame($expectedMin, $min);
        $this->assertSame($expectedMax, $max);
    }

    public static function ageRangeProvider(): array
    {
        return [
            'range with let' => ['pro děti 3-6 let', 3, 6],
            'range with roku' => ['věk 5-10 roku', 5, 10],
            'range with years' => ['ages 3-6 years', 3, 6],
            'range no unit' => ['3-6', 3, 6],
            'plus format' => ['5+ let', 5, null],
            'od format' => ['od 3 roku', 3, null],
            'pro děti range' => ['pro děti 4-8', 4, 8],
            'no age info' => ['koncert v Ostravě', null, null],
            'empty string' => ['', null, null],
        ];
    }

    // --- Kid-friendly detection ---

    public function test_kid_friendly_when_age_range_present(): void
    {
        $this->assertTrue($this->callDetectKidFriendly('some event', 3, 6));
    }

    public function test_kid_friendly_when_age_min_present(): void
    {
        $this->assertTrue($this->callDetectKidFriendly('some event', 5, null));
    }

    #[DataProvider('kidFriendlyKeywordProvider')]
    public function test_kid_friendly_by_keyword(string $text): void
    {
        $normalizeMethod = new ReflectionMethod(RulesEnrichmentProvider::class, 'normalizeText');
        $normalized = $normalizeMethod->invoke($this->provider, $text);

        $this->assertTrue($this->callDetectKidFriendly($normalized, null, null));
    }

    public static function kidFriendlyKeywordProvider(): array
    {
        return [
            'czech děti' => ['Akce pro děti'],
            'english kids' => ['Fun for kids'],
            'english family' => ['Family event in Ostrava'],
            'czech rodinn' => ['Rodinný den'],
            'czech pohádk' => ['Pohádka o princezně'],
        ];
    }

    public function test_kid_friendly_null_when_no_signals(): void
    {
        $this->assertNull($this->callDetectKidFriendly('koncert metal v ostravě', null, null));
    }

    // --- Indoor/Outdoor ---

    #[DataProvider('indoorOutdoorProvider')]
    public function test_detect_indoor_outdoor(string $text, string $expected): void
    {
        $normalizeMethod = new ReflectionMethod(RulesEnrichmentProvider::class, 'normalizeText');
        $normalized = $normalizeMethod->invoke($this->provider, $text);

        $this->assertSame($expected, $this->callDetectIndoorOutdoor($normalized));
    }

    public static function indoorOutdoorProvider(): array
    {
        return [
            'divadlo is indoor' => ['Divadlo loutek Ostrava', 'indoor'],
            'kino is indoor' => ['Kino v centru', 'indoor'],
            'museum is indoor' => ['Muzeum výtvarného umění', 'indoor'],
            'park is outdoor' => ['Akce v parku', 'outdoor'],
            'venku is outdoor' => ['Akce venku', 'outdoor'],
            'zahrada is outdoor' => ['Botanická zahrada', 'outdoor'],
            'both' => ['Divadlo a zahrada', 'both'],
            'unknown' => ['Koncert v Ostravě', 'unknown'],
        ];
    }

    // --- Category ---

    #[DataProvider('categoryProvider')]
    public function test_detect_category(string $text, string $expected): void
    {
        $normalizeMethod = new ReflectionMethod(RulesEnrichmentProvider::class, 'normalizeText');
        $normalized = $normalizeMethod->invoke($this->provider, $text);

        $this->assertSame($expected, $this->callDetectCategory($normalized));
    }

    public static function categoryProvider(): array
    {
        return [
            'theatre' => ['Divadlo loutek', 'theatre'],
            'music' => ['Koncert pro děti', 'music'],
            'festival' => ['Festival barev', 'festival'],
            'workshop' => ['Tvořivá dílna', 'workshop'],
            'education' => ['Přednáška o vesmíru', 'education'],
            'sports' => ['Sportovní den', 'sports'],
            'nature' => ['Zoo Ostrava', 'nature'],
            'exhibition' => ['Výstava fotografií', 'exhibition'],
            'other' => ['Setkání přátel', 'other'],
        ];
    }

    // --- Language detection ---

    #[DataProvider('languageProvider')]
    public function test_detect_language(string $text, string $expected): void
    {
        $normalizeMethod = new ReflectionMethod(RulesEnrichmentProvider::class, 'normalizeText');
        $normalized = $normalizeMethod->invoke($this->provider, $text);

        $this->assertSame($expected, $this->callDetectLanguage($normalized));
    }

    public static function languageProvider(): array
    {
        return [
            'czech diacritics' => ['Divadlo pro děti v Ostravě', 'cs'],
            'english only' => ['kids family workshop', 'en'],
            'mixed' => ['Workshop pro děti - family fun', 'mixed'],
            'unknown' => ['Event 2026', 'unknown'],
        ];
    }

    // --- Summary building ---

    public function test_build_summary_from_description(): void
    {
        $result = $this->callBuildSummary([
            'title' => 'My Event',
            'description_raw' => 'A fun event for families in Ostrava.',
        ]);

        $this->assertSame('A fun event for families in Ostrava.', $result);
    }

    public function test_build_summary_falls_back_to_title(): void
    {
        $result = $this->callBuildSummary([
            'title' => 'My Event Title',
            'description_raw' => '',
        ]);

        $this->assertSame('My Event Title', $result);
    }

    public function test_build_summary_truncates_to_200_chars(): void
    {
        $long = str_repeat('a', 250);
        $result = $this->callBuildSummary([
            'title' => 'Title',
            'description_raw' => $long,
        ]);

        $this->assertSame(200, mb_strlen($result));
        $this->assertStringEndsWith('...', $result);
    }

    public function test_build_summary_returns_null_for_empty_input(): void
    {
        $result = $this->callBuildSummary([
            'title' => '',
            'description_raw' => '',
        ]);

        $this->assertNull($result);
    }

    // --- Full deriveFields integration ---

    public function test_derive_fields_returns_all_expected_keys(): void
    {
        $fields = $this->callDeriveFields([
            'title' => 'Divadlo pro děti 3-6 let',
            'description_raw' => 'Loutková pohádka v divadle.',
            'location_name' => 'Divadlo loutek Ostrava',
        ]);

        $this->assertArrayHasKey('kid_friendly', $fields);
        $this->assertArrayHasKey('age_min', $fields);
        $this->assertArrayHasKey('age_max', $fields);
        $this->assertArrayHasKey('indoor_outdoor', $fields);
        $this->assertArrayHasKey('category', $fields);
        $this->assertArrayHasKey('language', $fields);
        $this->assertArrayHasKey('short_summary', $fields);
        $this->assertArrayHasKey('title_i18n', $fields);
        $this->assertArrayHasKey('short_summary_i18n', $fields);

        $this->assertTrue($fields['kid_friendly']);
        $this->assertSame(3, $fields['age_min']);
        $this->assertSame(6, $fields['age_max']);
        $this->assertSame('indoor', $fields['indoor_outdoor']);
        $this->assertSame('theatre', $fields['category']);
        $this->assertSame('cs', $fields['language']);
        $this->assertNotNull($fields['short_summary']);
        $this->assertNull($fields['title_i18n']);
        $this->assertNull($fields['short_summary_i18n']);
    }
}
