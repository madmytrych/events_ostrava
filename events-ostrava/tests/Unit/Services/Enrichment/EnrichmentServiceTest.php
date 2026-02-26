<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Enrichment;

use App\DTO\EnrichmentResult;
use App\Models\Event;
use App\Services\Enrichment\Contracts\EnrichmentProviderInterface;
use App\Services\Enrichment\EnrichmentService;
use App\Services\Enrichment\Providers\AiEnrichmentProvider;
use App\Services\Enrichment\Providers\RulesEnrichmentProvider;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class EnrichmentServiceTest extends TestCase
{
    private function aiResult(): EnrichmentResult
    {
        return new EnrichmentResult(logId: 1, fields: ['short_summary' => 'AI summary'], mode: 'ai');
    }

    private function rulesResult(): EnrichmentResult
    {
        return new EnrichmentResult(logId: 2, fields: ['short_summary' => 'Rules summary'], mode: 'rules');
    }

    private function buildService(
        EnrichmentProviderInterface $ai,
        EnrichmentProviderInterface $rules,
    ): EnrichmentService {
        return new EnrichmentService($ai, $rules);
    }

    private function makeEvent(): Event
    {
        $event = $this->createMock(Event::class);
        $event->method('__get')->willReturn(null);

        return $event;
    }

    private function fakeAiProvider(?EnrichmentResult $result = null, ?\Throwable $exception = null): EnrichmentProviderInterface
    {
        return new class ($result, $exception) implements EnrichmentProviderInterface {
            public int $callCount = 0;

            public function __construct(
                private readonly ?EnrichmentResult $result,
                private readonly ?\Throwable $exception,
            ) {}

            public function enrich(Event $event, string $reason = 'rules'): EnrichmentResult
            {
                $this->callCount++;
                if ($this->exception) {
                    throw $this->exception;
                }

                return $this->result;
            }
        };
    }

    private function fakeRulesProvider(?EnrichmentResult $result = null): EnrichmentProviderInterface
    {
        return new class ($result) implements EnrichmentProviderInterface {
            public int $callCount = 0;

            public function __construct(private readonly ?EnrichmentResult $result) {}

            public function enrich(Event $event, string $reason = 'rules'): EnrichmentResult
            {
                $this->callCount++;

                return $this->result;
            }
        };
    }

    public function test_mode_ai_uses_ai_provider(): void
    {
        Config::set('enrichment.mode', 'ai');
        Config::set('enrichment.ai_enabled', true);

        $ai = $this->fakeAiProvider($this->aiResult());
        $rules = $this->fakeRulesProvider($this->rulesResult());
        $service = $this->buildService($ai, $rules);

        $result = $service->enrich($this->makeEvent());

        $this->assertSame('ai', $result->mode);
        $this->assertSame(1, $ai->callCount);
        $this->assertSame(0, $rules->callCount);
    }

    public function test_mode_rules_uses_rules_provider(): void
    {
        Config::set('enrichment.mode', 'rules');
        Config::set('enrichment.ai_enabled', false);

        $ai = $this->fakeAiProvider($this->aiResult());
        $rules = $this->fakeRulesProvider($this->rulesResult());
        $service = $this->buildService($ai, $rules);

        $result = $service->enrich($this->makeEvent());

        $this->assertSame('rules', $result->mode);
        $this->assertSame(0, $ai->callCount);
        $this->assertSame(1, $rules->callCount);
    }

    public function test_mode_hybrid_tries_ai_first(): void
    {
        Config::set('enrichment.mode', 'hybrid');
        Config::set('enrichment.ai_enabled', true);

        $ai = $this->fakeAiProvider($this->aiResult());
        $rules = $this->fakeRulesProvider($this->rulesResult());
        $service = $this->buildService($ai, $rules);

        $result = $service->enrich($this->makeEvent());

        $this->assertSame('ai', $result->mode);
        $this->assertSame(1, $ai->callCount);
        $this->assertSame(0, $rules->callCount);
    }

    public function test_mode_hybrid_falls_back_to_rules_on_ai_failure(): void
    {
        Config::set('enrichment.mode', 'hybrid');
        Config::set('enrichment.ai_enabled', true);

        $ai = $this->fakeAiProvider(exception: new \RuntimeException('API error'));
        $rules = $this->fakeRulesProvider($this->rulesResult());
        $service = $this->buildService($ai, $rules);

        $result = $service->enrich($this->makeEvent());

        $this->assertSame('rules', $result->mode);
        $this->assertSame(1, $ai->callCount);
        $this->assertSame(1, $rules->callCount);
    }

    public function test_mode_ai_propagates_exception_without_fallback(): void
    {
        Config::set('enrichment.mode', 'ai');
        Config::set('enrichment.ai_enabled', true);

        $ai = $this->fakeAiProvider(exception: new \RuntimeException('API error'));
        $rules = $this->fakeRulesProvider($this->rulesResult());
        $service = $this->buildService($ai, $rules);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('API error');

        $service->enrich($this->makeEvent());
    }

    public function test_mode_hybrid_with_ai_disabled_uses_rules(): void
    {
        Config::set('enrichment.mode', 'hybrid');
        Config::set('enrichment.ai_enabled', false);

        $ai = $this->fakeAiProvider($this->aiResult());
        $rules = $this->fakeRulesProvider($this->rulesResult());
        $service = $this->buildService($ai, $rules);

        $result = $service->enrich($this->makeEvent());

        $this->assertSame('rules', $result->mode);
        $this->assertSame(0, $ai->callCount);
        $this->assertSame(1, $rules->callCount);
    }
}
