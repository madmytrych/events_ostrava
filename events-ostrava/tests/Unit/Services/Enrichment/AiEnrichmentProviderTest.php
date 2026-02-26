<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Enrichment;

use App\Services\Enrichment\Providers\AiEnrichmentProvider;
use App\Services\Enrichment\Contracts\LlmClientInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class AiEnrichmentProviderTest extends TestCase
{
    private AiEnrichmentProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $client = $this->createMock(LlmClientInterface::class);
        $this->provider = new AiEnrichmentProvider($client);
    }

    // --- normalizeBool ---

    #[DataProvider('normalizeBoolProvider')]
    public function test_normalize_bool(mixed $input, ?bool $expected): void
    {
        $method = new ReflectionMethod(AiEnrichmentProvider::class, 'normalizeBool');
        $this->assertSame($expected, $method->invoke($this->provider, $input));
    }

    public static function normalizeBoolProvider(): array
    {
        return [
            'true bool' => [true, true],
            'false bool' => [false, false],
            'null' => [null, null],
            'string true' => ['true', true],
            'string True' => ['True', true],
            'string yes' => ['yes', true],
            'string 1' => ['1', true],
            'string false' => ['false', false],
            'string no' => ['no', false],
            'string 0' => ['0', false],
            'int 1' => [1, true],
            'int 0' => [0, false],
            'unrecognized string' => ['maybe', null],
            'empty string' => ['', null],
        ];
    }

    // --- normalizeInt ---

    #[DataProvider('normalizeIntProvider')]
    public function test_normalize_int(mixed $input, int $min, int $max, ?int $expected): void
    {
        $method = new ReflectionMethod(AiEnrichmentProvider::class, 'normalizeInt');
        $this->assertSame($expected, $method->invoke($this->provider, $input, $min, $max));
    }

    public static function normalizeIntProvider(): array
    {
        return [
            'valid int' => [5, 0, 120, 5],
            'valid string' => ['10', 0, 120, 10],
            'zero' => [0, 0, 120, 0],
            'at max' => [120, 0, 120, 120],
            'below min' => [-1, 0, 120, null],
            'above max' => [121, 0, 120, null],
            'null' => [null, 0, 120, null],
            'empty string' => ['', 0, 120, null],
            'non-numeric' => ['abc', 0, 120, null],
            'float string' => ['3.5', 0, 120, 3],
        ];
    }

    // --- normalizeEnum ---

    #[DataProvider('normalizeEnumProvider')]
    public function test_normalize_enum(mixed $input, array $allowed, ?string $expected): void
    {
        $method = new ReflectionMethod(AiEnrichmentProvider::class, 'normalizeEnum');
        $this->assertSame($expected, $method->invoke($this->provider, $input, $allowed));
    }

    public static function normalizeEnumProvider(): array
    {
        $allowed = ['indoor', 'outdoor', 'both', 'unknown'];

        return [
            'exact match' => ['indoor', $allowed, 'indoor'],
            'uppercase normalized' => ['INDOOR', $allowed, 'indoor'],
            'with spaces' => [' outdoor ', $allowed, 'outdoor'],
            'invalid value' => ['inside', $allowed, null],
            'null' => [null, $allowed, null],
            'integer input' => [123, $allowed, null],
            'empty string' => ['', $allowed, null],
        ];
    }

    // --- normalizeSummary ---

    #[DataProvider('normalizeSummaryProvider')]
    public function test_normalize_summary(mixed $input, ?string $expected): void
    {
        $method = new ReflectionMethod(AiEnrichmentProvider::class, 'normalizeSummary');
        $result = $method->invoke($this->provider, $input);

        if ($expected === null) {
            $this->assertNull($result);
        } else {
            $this->assertSame($expected, $result);
        }
    }

    public static function normalizeSummaryProvider(): array
    {
        return [
            'normal string' => ['A fun event for kids.', 'A fun event for kids.'],
            'trimmed' => ['  spaced  ', 'spaced'],
            'empty after trim' => ['   ', null],
            'null input' => [null, null],
            'non-string' => [123, null],
        ];
    }

    public function test_normalize_summary_truncates_to_200(): void
    {
        $method = new ReflectionMethod(AiEnrichmentProvider::class, 'normalizeSummary');
        $long = str_repeat('x', 250);
        $result = $method->invoke($this->provider, $long);

        $this->assertSame(200, mb_strlen($result));
    }

    // --- normalizeFields (integration of all normalizers) ---

    public function test_normalize_fields_with_valid_data(): void
    {
        $method = new ReflectionMethod(AiEnrichmentProvider::class, 'normalizeFields');

        $parsed = [
            'is_kid_friendly' => true,
            'age_min' => 3,
            'age_max' => 6,
            'indoor_outdoor' => 'indoor',
            'category' => 'theatre',
            'language' => 'cs',
            'short_summary' => 'A puppet show for kids.',
            'title_en' => 'Puppet Show',
            'title_uk' => 'Лялькова вистава',
            'short_summary_en' => 'A puppet show for kids.',
            'short_summary_uk' => 'Лялькова вистава для дітей.',
        ];

        $result = $method->invoke($this->provider, $parsed);

        $this->assertTrue($result['kid_friendly']);
        $this->assertSame(3, $result['age_min']);
        $this->assertSame(6, $result['age_max']);
        $this->assertSame('indoor', $result['indoor_outdoor']);
        $this->assertSame('theatre', $result['category']);
        $this->assertSame('cs', $result['language']);
        $this->assertSame('A puppet show for kids.', $result['short_summary']);
        $this->assertSame(['en' => 'Puppet Show', 'uk' => 'Лялькова вистава'], $result['title_i18n']);
        $this->assertSame(['en' => 'A puppet show for kids.', 'uk' => 'Лялькова вистава для дітей.'], $result['short_summary_i18n']);
    }

    public function test_normalize_fields_with_missing_keys(): void
    {
        $method = new ReflectionMethod(AiEnrichmentProvider::class, 'normalizeFields');
        $result = $method->invoke($this->provider, []);

        $this->assertNull($result['kid_friendly']);
        $this->assertNull($result['age_min']);
        $this->assertNull($result['age_max']);
        $this->assertNull($result['indoor_outdoor']);
        $this->assertNull($result['category']);
        $this->assertNull($result['language']);
        $this->assertNull($result['short_summary']);
        $this->assertNull($result['title_i18n']);
        $this->assertNull($result['short_summary_i18n']);
    }

    public function test_normalize_fields_with_invalid_values(): void
    {
        $method = new ReflectionMethod(AiEnrichmentProvider::class, 'normalizeFields');

        $parsed = [
            'is_kid_friendly' => 'maybe',
            'age_min' => 'three',
            'age_max' => 999,
            'indoor_outdoor' => 'inside',
            'category' => 'fun',
            'language' => 'klingon',
            'short_summary' => '',
        ];

        $result = $method->invoke($this->provider, $parsed);

        $this->assertNull($result['kid_friendly']);
        $this->assertNull($result['age_min']);
        $this->assertNull($result['age_max']);
        $this->assertNull($result['indoor_outdoor']);
        $this->assertNull($result['category']);
        $this->assertNull($result['language']);
        $this->assertNull($result['short_summary']);
        $this->assertNull($result['title_i18n']);
        $this->assertNull($result['short_summary_i18n']);
    }

    public function test_normalize_fields_extracts_i18n_translations(): void
    {
        $method = new ReflectionMethod(AiEnrichmentProvider::class, 'normalizeFields');

        $parsed = [
            'is_kid_friendly' => null,
            'title_en' => 'Event Title',
            'title_uk' => 'Назва події',
            'short_summary_en' => 'A brief summary.',
            'short_summary_uk' => 'Короткий опис.',
        ];

        $result = $method->invoke($this->provider, $parsed);

        $this->assertSame(['en' => 'Event Title', 'uk' => 'Назва події'], $result['title_i18n']);
        $this->assertSame(['en' => 'A brief summary.', 'uk' => 'Короткий опис.'], $result['short_summary_i18n']);
    }

    public function test_normalize_fields_i18n_partial_translations(): void
    {
        $method = new ReflectionMethod(AiEnrichmentProvider::class, 'normalizeFields');

        $parsed = [
            'title_en' => 'Only English',
            'title_uk' => null,
            'short_summary_en' => '',
            'short_summary_uk' => 'Тільки українська.',
        ];

        $result = $method->invoke($this->provider, $parsed);

        $this->assertSame(['en' => 'Only English'], $result['title_i18n']);
        $this->assertSame(['uk' => 'Тільки українська.'], $result['short_summary_i18n']);
    }

    // --- buildPrompt ---

    public function test_build_prompt_includes_event_data(): void
    {
        $method = new ReflectionMethod(AiEnrichmentProvider::class, 'buildPrompt');

        $attrs = [
            'title' => 'Puppet Show',
            'description_raw' => 'A lovely puppet show.',
            'description' => null,
            'start_at' => \Illuminate\Support\Carbon::parse('2026-03-15 10:00:00'),
            'end_at' => null,
            'location_name' => 'Theatre Ostrava',
            'venue' => null,
            'source_url' => 'https://example.com/event/1',
        ];

        $event = $this->createMock(\App\Models\Event::class);
        $event->method('__get')->willReturnCallback(fn (string $key) => $attrs[$key] ?? null);
        $event->method('__isset')->willReturnCallback(fn (string $key) => isset($attrs[$key]));
        $event->method('offsetExists')->willReturnCallback(fn (string $key) => isset($attrs[$key]));

        $prompt = $method->invoke($this->provider, $event);

        $this->assertStringContainsString('Puppet Show', $prompt);
        $this->assertStringContainsString('A lovely puppet show.', $prompt);
        $this->assertStringContainsString('Theatre Ostrava', $prompt);
        $this->assertStringContainsString('https://example.com/event/1', $prompt);
        $this->assertStringContainsString('JSON', $prompt);
        $this->assertStringContainsString('title_en', $prompt);
        $this->assertStringContainsString('title_uk', $prompt);
        $this->assertStringContainsString('short_summary_en', $prompt);
        $this->assertStringContainsString('short_summary_uk', $prompt);
    }
}
